<?php

namespace App\Http\Middleware;

use Closure;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Signer\Key\InMemory;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use Hdruk\ClaimsAccessControl\Services\ClaimMappingService;
use Hdruk\ClaimsAccessControl\Services\ClaimResolverService;

use App\Traits\Responses;

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
        // Decode JWT token and check claims
        $request->header('Authorization');
        $token = $request->bearerToken();

        $signer = new Sha256();
        $key = InMemory::plainText(config('gateway.jwt_secret'));

        $config = Configuration::forSymmetricSigner($signer, $key);
        $token = $config->parser()->parse($token);

        /** @phpstan-ignore-next-line */
        $user = $token->claims()->get('user');

        $claimMappingService = new ClaimMappingService();
        $claimResolverService = new ClaimResolverService($claimMappingService);

        // normalise the workgroup claims to determine access
        $newArr = $this->normaliseWorkgroups($user['workgroups']);
        unset($user['workgroups']);
        $user['workgroups'] = $newArr['workgroups'];

        foreach ($claims as $claim) {
            $resolution = $claimResolverService->hasWorkgroup($user, config('claims-access.default_system'), $claim);

            if ($resolution) {
                return $next($request);
            }
        }

        // Return unauthorized response if claim is not found
        return $this->UnauthorisedResponse();
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