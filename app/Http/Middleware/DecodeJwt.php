<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\Custodian;
use App\Models\User;
use App\Models\Workgroup;
use App\Support\ApplicationMode;
use Closure;
use App\Traits\Workgroups;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Hdruk\ClaimsAccessControl\Services\ClaimMappingService;

class DecodeJwt
{
    use Workgroups;

    private const CACHE_DONE_PREFIX = 'jwt_sync_done:v1:integrated:';
    private const CACHE_LOCK_PREFIX = 'jwt_sync_lock:v1:integrated:';

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $startMicrotime = microtime(true);

        try {
            if (! $token) {
                return response()->json(['error' => 'No token'], 401);
            }

            if (! ApplicationMode::isStandalone()) {
                try {
                    $key = config('integrated.jwt_secret');

                    if (! $key) {
                        throw new \Exception('No jwt secret provided, cant decode safely');
                    }

                    $claims = JWT::decode($token, new Key($key, 'HS256'));
                    $request->attributes->set('jwt_claims', (array) $claims);

                    $jwtUser = $claims->user ?? null;
                    if (! $jwtUser) {
                        return response()->json(['error' => 'Invalid token: Unknown user'], 401);
                    }

                    $userEmail = $jwtUser->email ?? null;
                    if (is_null($userEmail)) {
                        return response()->json(['error' => 'Invalid token: no user email'], 401);
                    }

                    $user = User::where('email', strtolower($userEmail))->first();
                    if (! $user) {
                        return response()->json(['error' => 'Cannot find token user in local database'], 401);
                    }

                    Auth::setUser($user);

                    //$jti = $this->claimsJtiOrFail($claims);
                    //$this->forgetIntegratedTokenSyncCacheByJti($jti);

                    $this->syncIntegratedOncePerToken($claims, $user, $jwtUser);
                } catch (\Exception $e) {
                    return response()->json(['error' => 'Invalid token: '.$e->getMessage()], 401);
                }
            } else {
                $privateKeyEnv = config('passport.private_key');
                $publicKeyEnv  = config('passport.public_key');

                $privateKey = $privateKeyEnv
                    ? InMemory::plainText($privateKeyEnv)
                    : InMemory::file(storage_path('oauth-private.key'));

                $publicKey = $publicKeyEnv
                    ? InMemory::plainText($publicKeyEnv)
                    : InMemory::file(storage_path('oauth-public.key'));

                $jwtConfig = Configuration::forAsymmetricSigner(
                    new Sha256(),
                    $privateKey,
                    $publicKey
                );

                /** @var \Lcobucci\JWT\UnencryptedToken $jwt */
                $jwt = $jwtConfig->parser()->parse($token);

                $jwtUser = $jwt->claims()->get('user');
                if (! $jwtUser) {
                    return response()->json(['error' => 'Invalid token: Unknown user'], 401);
                }

                $user = User::where('email', $jwtUser['email'])->first();
                if (! $user) {
                    return response()->json(['error' => 'cannot find token user in local database'], 401);
                }

                Auth::setUser($user);
            }

            return $next($request);
        } finally {
            $endMicrotime = microtime(true);
            $durationMs = ($endMicrotime - $startMicrotime) * 1000;
            \Log::debug('Middleware DecodeJwt finished in '. round($durationMs, 2) . ' ms');
        }
    }

    protected function syncIntegratedOncePerToken(
        object $claims,
        User $user,
        object $jwtUser,
    ): void {
        $ttl = $this->claimsTtlSeconds($claims);
        $jti = $this->claimsJtiOrFail($claims);

        $cacheKey = $this->cacheDoneKey($jti);
        $lockKey  = $this->cacheLockKey($jti);

        if (Cache::get($cacheKey)) {
            return;
        }

        Cache::lock($lockKey, 10)->block(2, function () use ($cacheKey, $ttl, $user, $jwtUser) {
            if (Cache::get($cacheKey)) {
                return;
            }

            $this->syncWorkgroups($user, $jwtUser);
            $this->syncRoles($user, $jwtUser);
            $this->syncCustodians($user, $jwtUser);

            Cache::put($cacheKey, true, $ttl);
        });
    }

    protected function claimsTtlSeconds(object $claims): int
    {
        $now = time();
        $exp = isset($claims->exp) ? (int) $claims->exp : null;

        if (! $exp) {
            throw new \Exception('Invalid token: exp claim is required');
        }

        return max(1, $exp - $now);
    }

    protected function claimsJtiOrFail(object $claims): string
    {
        $jti = $claims->jti ?? null;

        if (! is_string($jti) || $jti === '') {
            throw new \Exception('Invalid token: jti claim is required');
        }

        return $jti;
    }

    protected function cacheDoneKey(string $jti): string
    {
        return self::CACHE_DONE_PREFIX.$jti;
    }

    protected function cacheLockKey(string $jti): string
    {
        return self::CACHE_LOCK_PREFIX.$jti;
    }

    protected function syncWorkgroups(User $user, object $jwtUser): void
    {
        $cms = app(ClaimMappingService::class);
        $workgroupMap = $cms->getMap()[config('claims-access.default_system')] ?? [];
        $externalWorkgroups = $jwtUser->workgroups ?? null;

        if (! isset($externalWorkgroups)) {
            throw new \Exception('Invalid token: no workgroups set');
        }

        $externalNames = collect($externalWorkgroups)
            ->values()
            ->all();

        $internalNames = collect($workgroupMap)
            ->filter(fn ($internal) => in_array($internal, $externalNames, true))
            ->keys()
            ->values()
            ->all();

        $workgroupIds = Workgroup::query()
            ->whereIn(\DB::raw('LOWER(name)'), $internalNames)
            ->pluck('id')
            ->toArray();

        $user->workgroups()->sync($workgroupIds);
    }

    protected function syncRoles(User $user, object $jwtUser): void
    {
        $roleMap = config('claimsaccesscontrol.role_mappings');
        $externalRoles = $jwtUser->cohort_discovery_roles ?? null;

        if (! isset($externalRoles)) {
            throw new \Exception('Invalid token: no roles set');
        }

        $externalNames = collect($externalRoles)
            ->filter()
            ->values()
            ->all();

        $internalNames = collect($roleMap)
            ->filter(fn ($external) => in_array($external, $externalNames, true))
            ->keys()
            ->map(fn ($n) => mb_strtolower($n))
            ->values()
            ->all();

        $roleIds = Role::query()
            ->whereIn(\DB::raw('LOWER(name)'), $internalNames)
            ->pluck('id')
            ->toArray();

        $user->roles()->sync($roleIds);
    }

    protected function syncCustodians(User $user, object $jwtUser): void
    {
        $teams = $jwtUser->cohort_admin_teams ?? [];
        $rows = collect($teams)->map(fn ($t) => [
            'external_custodian_id' => $t->id,
            'name' => $t->name,
            'external_custodian_name' => $t->name,
        ])->all();

        if (count($rows) === 0) {
            $user->custodians()->sync([]);
            return;
        }

        Custodian::upsert(
            $rows,
            ['external_custodian_id'],
            ['name', 'external_custodian_name']
        );

        $externalIds = collect($teams)
            ->pluck('id')
            ->values()
            ->all();

        $custodianIds = Custodian::query()
            ->whereIn('external_custodian_id', $externalIds)
            ->pluck('id')
            ->all();

        $user->custodians()->sync($custodianIds);
    }

    public static function forgetIntegratedTokenSyncCacheByJti(string $jti): void
    {
        Cache::forget(self::CACHE_DONE_PREFIX.$jti);
        Cache::forget(self::CACHE_LOCK_PREFIX.$jti);
    }
}
