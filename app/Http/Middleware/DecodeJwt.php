<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApplicationMode;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Illuminate\Contracts\Cache\LockTimeoutException;
use App\Services\TokenSync\RoleSyncerService;
use App\Services\TokenSync\CustodianSyncerService;
use App\Services\TokenSync\WorkgroupSyncerService;

class DecodeJwt
{
    private const CACHE_DONE_PREFIX = 'jwt_sync_done:v1:integrated:';
    private const CACHE_LOCK_PREFIX = 'jwt_sync_lock:v1:integrated:';

    public function __construct(
        private readonly WorkgroupSyncerService $workgroupSyncer,
        private readonly RoleSyncerService $roleSyncer,
        private readonly CustodianSyncerService $custodianSyncer,
    ) {
    }

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
        $ttl = $this->claimsTtlSeconds($claims);
        $jti = $this->claimsJtiOrFail($claims);

        $cacheKey = $this->cacheDoneKey($jti);
        $lockKey  = $this->cacheLockKey($jti);

        if (Cache::get($cacheKey)) {
            return;
        }

        $lockSeconds = (int) config('claimsaccesscontrol.sync_lock_seconds', 30);
        $waitSeconds = (int) config('claimsaccesscontrol.sync_lock_wait_seconds', 5);

        try {
            Cache::lock($lockKey, $lockSeconds)->block($waitSeconds, function () use ($cacheKey, $ttl, $user, $jwtUser, $jti) {
                if (Cache::get($cacheKey)) {
                    \Log::info("JWT sync already done for jti={$jti} - skipping");
                    return;
                }

                $this->workgroupSyncer->sync(
                    $user,
                    $jwtUser->workgroups ?? [],
                    $jwtUser->is_nhse_sde_approval ?? false,
                );

                $this->roleSyncer->sync(
                    $user,
                    $jwtUser->cohort_discovery_roles ?? []
                );

                $this->custodianSyncer->sync(
                    $user,
                    $jwtUser->cohort_admin_teams ?? []
                );


                Cache::put($cacheKey, true, $ttl);

                \Log::info("JWT synced + cached done for jti={$jti}");
            });
        } catch (LockTimeoutException $e) {
            \Log::warning("JWT sync lock timeout for jti={$jti} (waitSeconds={$waitSeconds}) - skipping sync this request");
            return;
        }
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

    public static function forgetIntegratedTokenSyncCacheByJti(string $jti): void
    {
        Cache::forget(self::CACHE_DONE_PREFIX.$jti);
        Cache::forget(self::CACHE_LOCK_PREFIX.$jti);
    }
}
