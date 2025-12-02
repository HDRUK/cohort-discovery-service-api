<?php

namespace App\Services\Authentication;

use App\Models\User;
use App\Support\ApplicationMode;
use Laravel\Passport\PersonalAccessTokenFactory;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token;

class LocalPersonalAccessTokenService
{
    protected Configuration $jwtConfig;

    public function __construct(
        protected PersonalAccessTokenFactory $factory
    ) {

        $privateKeyEnv = config('passport.private_key');
        $publicKeyEnv  = config('passport.public_key');

        $privateKey = $privateKeyEnv
            ? InMemory::plainText($privateKeyEnv)
            : InMemory::file(storage_path('oauth-private.key'));

        $publicKey = $publicKeyEnv
            ? InMemory::plainText($publicKeyEnv)
            : InMemory::file(storage_path('oauth-public.key'));

        $this->jwtConfig = Configuration::forAsymmetricSigner(
            new \Lcobucci\JWT\Signer\Rsa\Sha256(),
            $privateKey,
            $publicKey
        );
    }

    /**
     * Create a personal access token for a user with optional extra claims.
     */
    public function makeForUser(User $user, string $name = 'local_login', array $scopes = ['*'])
    {
        // Generate the token using Passport
        $tokenResult = $this->factory->make(
            $user->getKey(),
            $name,
            $scopes,
            'users'
        );

        if (ApplicationMode::isStandalone()) {
            $tokenString = $tokenResult->accessToken;

            /** @var \Lcobucci\JWT\UnencryptedToken $jwt */
            $jwt = $this->jwtConfig->parser()->parse($tokenString);

            $userObj = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'cohort_discovery_roles' => $user->role_names,
                'workgroups' => $user->workgroups,
                'cohort_admin_teams' => $user->custodians,
            ];

            $builder = $this->jwtConfig->builder()
                ->identifiedBy($jwt->claims()->get('jti'))
                ->issuedAt($jwt->claims()->get('iat'))
                ->expiresAt($jwt->claims()->get('exp'))
                ->withClaim('user', $userObj);

            $newToken = $builder->getToken(
                $this->jwtConfig->signer(),
                $this->jwtConfig->signingKey()
            );

            $tokenResult->accessToken = $newToken->toString();
        }

        return $tokenResult;
    }
}
