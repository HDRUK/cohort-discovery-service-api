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

    public function handle(Request $request, Closure $next)
    {
        $cms = app(ClaimMappingService::class);
        $roleMap = config('claimsaccesscontrol.role_mappings');

        $token = $request->bearerToken();
        $startMicrotime = microtime(true);

        $this->forgetIntegratedTokenSyncCache($token);

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

                    $this->syncIntegratedOncePerToken($token, $claims, $user, $jwtUser, $cms, $roleMap);
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
        string $token,
        object $claims,
        User $user,
        object $jwtUser,
        ClaimMappingService $cms,
        array $roleMap
    ): void {
        $ttl = $this->claimsTtlSeconds($claims);
        $fingerprint = $this->claimsFingerprint($claims, $token);

        $cacheKey = "jwt_sync_done:v1:integrated:{$fingerprint}";
        $lockKey  = "jwt_sync_lock:v1:integrated:{$fingerprint}";

        if (Cache::get($cacheKey)) {
            return;
        }

        Cache::lock($lockKey, 10)->block(2, function () use ($cacheKey, $ttl, $user, $jwtUser, $cms, $roleMap) {

            $this->syncWorkgroups($user, $jwtUser, $cms);
            $this->syncRoles($user, $jwtUser, $roleMap);
            $this->syncCustodians($user, $jwtUser);

            Cache::put($cacheKey, true, $ttl);
        });
    }

    protected function claimsTtlSeconds(object $claims): int
    {
        $now = time();
        $exp = isset($claims->exp) ? (int) $claims->exp : ($now + 3600);
        return max(1, $exp - $now);
    }

    protected function claimsFingerprint(object $claims, string $token): string
    {
        if (isset($claims->jti) && is_string($claims->jti) && $claims->jti !== '') {
            return $claims->jti;
        }

        return hash('sha256', $token);
    }

    protected function syncWorkgroups(User $user, object $jwtUser, ClaimMappingService $cms): void
    {
        $workgroupMap = $cms->getMap()[config('claims-access.default_system')] ?? [];
        $externalWorkgroups = $jwtUser->workgroups ?? null;

        if (! isset($externalWorkgroups)) {
            throw new \Exception('Invalid token: no workgroups set');
        }

        $externalNames = collect($externalWorkgroups)
            ->pluck('name')
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

    protected function syncRoles(User $user, object $jwtUser, array $roleMap): void
    {
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

    public static function forgetIntegratedTokenSyncCache(string $tokenOrJti): void
    {
        $asJti = $tokenOrJti;
        $asHash = hash('sha256', $tokenOrJti);

        Cache::forget("jwt_sync_done:v1:integrated:{$asJti}");
        Cache::forget("jwt_sync_lock:v1:integrated:{$asJti}");

        Cache::forget("jwt_sync_done:v1:integrated:{$asHash}");
        Cache::forget("jwt_sync_lock:v1:integrated:{$asHash}");
    }
}
