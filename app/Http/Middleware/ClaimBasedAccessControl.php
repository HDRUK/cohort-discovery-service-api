<?php

namespace App\Http\Middleware;

use App\Support\ApplicationMode;
use App\Traits\Responses;
use Closure;
use Hdruk\ClaimsAccessControl\Services\ClaimResolverService;
use Illuminate\Http\Request;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\HttpFoundation\Response;

class ClaimBasedAccessControl
{
    use Responses;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$claims): Response
    {
        try {
            // Decode JWT token and check claims
            $request->header('Authorization');
            $token = $request->bearerToken();
            $key = null;

            $signer = new Sha256();
            if (ApplicationMode::isStandalone()) {
                $key = InMemory::plainText(config('api.jwt_secret'));
            } else {
                $key = InMemory::plainText(config('integrated.jwt_secret'));
            }

            $config = Configuration::forSymmetricSigner($signer, $key);
            $token = $config->parser()->parse($token);

            /** @phpstan-ignore-next-line */
            $user = $token->claims()->get('user');

            $claimResolverService = app(ClaimResolverService::class);

            // normalise the workgroup claims to determine access
            $newArr = $this->normaliseWorkgroups($user['workgroups']);

            $user['workgroups'] = $newArr['workgroups']; // cohort-admin

            foreach ($claims as $claim) {
                $resolution = $claimResolverService->hasWorkgroup(
                    $user,
                    config('claims-access.default_system'),
                    $claim
                );
                if ($resolution) {
                    return $next($request);
                }
            }

            // Return unauthorized response if claim is not found
            return $this->UnauthorisedResponse();
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function normaliseWorkgroups(array $data): array
    {
        $normalised = [];

        foreach ($data as $workgroup) {
            if (is_array($workgroup) && isset($workgroup['name'])) {
                $normalised[] = strtolower($workgroup['name']);
            }
        }

        return ['workgroups' => [config('claims-access.default_system') => $normalised]];
    }
}
