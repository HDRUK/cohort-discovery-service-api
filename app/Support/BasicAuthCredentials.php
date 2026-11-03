<?php

namespace App\Support;

use Illuminate\Http\Request;

final class BasicAuthCredentials
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
    ) {
    }

    public static function fromRequest(Request $request): ?self
    {
        $authorisationHeader = $request->header('Authorization');

        if (! $authorisationHeader || ! preg_match('/Basic\s+(.*)$/i', $authorisationHeader, $matches)) {
            return null;
        }

        $decodedCredentials = base64_decode($matches[1], true);

        if (! $decodedCredentials || strpos($decodedCredentials, ':') === false) {
            return null;
        }

        [$clientId, $clientSecret] = explode(':', $decodedCredentials, 2);

        return new self($clientId, $clientSecret);
    }
}
