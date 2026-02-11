<?php

namespace App\Http\Middleware;

use Spatie\Permission\Models\Role;
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
use Laravel\Pennant\Feature;

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
                        return response()->json(['error' => 'Cannot find token user in local database'], 404);
                    }

                    Auth::setUser($user);

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
                $request->attributes->set('jwt_claims', $jwt->claims()->all());
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
        error_log('yoodasdasdasoo');
        $ttl = $this->claimsTtlSeconds($claims);
        $jti = $this->claimsJtiOrFail($claims);

        $cacheKey = $this->cacheDoneKey($jti);
        $lockKey  = $this->cacheLockKey($jti);

        if (Cache::get($cacheKey)) {
            //return;
        }

        $lockSeconds = config('claimsaccesscontrol.sync_lock_seconds', 30);
        $waitSeconds = config('claimsaccesscontrol.sync_lock_wait_seconds', 5);


        error_log('yooo');
        $this->syncWorkgroups($user, $jwtUser);
        $this->syncRoles($user, $jwtUser);
        $this->syncCustodians($user, $jwtUser);


        /*
                Cache::lock($lockKey, $lockSeconds)->block($waitSeconds, function () use ($cacheKey, $ttl, $user, $jwtUser) {
                    if (Cache::get($cacheKey)) {
                        \Log::info('Sync Cache locked - aborting');
                        return;
                    }

                    $this->syncWorkgroups($user, $jwtUser);
                    $this->syncRoles($user, $jwtUser);
                    $this->syncCustodians($user, $jwtUser);

                    Cache::put($cacheKey, true, $ttl);

                    \Log::info('Cached sync and locked');
                });*/
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
        $defaultWgIds = [];

        if (Feature::active('add-user-to-default-wg')) {
            $defaultWgId = Workgroup::where('name', 'DEFAULT')->value('id');
            if ($defaultWgId) {
                $defaultWgIds[] = $defaultWgId;
            }
        }

        $hasSdeApproval = $jwtUser->is_nhse_sde_approval ?? false;

        if ($hasSdeApproval && Feature::active('add-user-to-nhs-sde-wgs')) {
            $sdeWgIds = Workgroup::whereIn('name', [
                'NHS-SDE',
                'UK-INDUSTRY',
                'UK-RESEARCH',
            ])->pluck('id');

            $defaultWgIds = array_merge($defaultWgIds, $sdeWgIds->toArray());
        }

        if (Feature::active('manage-workgroups-internal')) {
            $user->workgroups()->sync($defaultWgIds);
            return;
        }

        $workgroupMap = config('claimsaccesscontrol.workgroup_mappings', []);
        $externalWorkgroups = $jwtUser->workgroups ?? null;

        if (! isset($externalWorkgroups)) {
            throw new \Exception('Invalid token: no workgroups set');
        }

        $externalNames = collect($externalWorkgroups)
            ->pluck('name')
            ->values()
            ->all();

        // Check if externalNames match either the keys (internal names that are also external)
        // or the values (configured external names) in the workgroupMap
        $internalNames = collect($workgroupMap)
            ->filter(
                fn ($externalValue, $internalKey) =>
                in_array($internalKey, $externalNames, true) ||
                in_array($externalValue, $externalNames, true)
            )
            ->keys()
            ->values()
            ->all();

        $workgroupIds = Workgroup::query()
            ->whereIn(\DB::raw('LOWER(name)'), $internalNames)
            ->pluck('id')
            ->toArray();

        $finalIds = array_values(array_unique(array_merge($defaultWgIds, $workgroupIds)));
        $user->workgroups()->sync($finalIds);
    }

    protected function syncRoles(User $user, object $jwtUser): void
    {
        $roleMap = config('claimsaccesscontrol.role_mappings');
        $externalRoles = $jwtUser->cohort_discovery_roles ?? null;

        if (! isset($externalRoles)) {
            $user->roles()->sync([]);
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

        \Log::info('syncing roles against user (' . $user->id . '): ' . json_encode($roleIds));
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
