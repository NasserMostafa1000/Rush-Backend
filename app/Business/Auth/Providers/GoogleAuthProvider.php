<?php

namespace App\Business\Auth\Providers;

use App\Business\Exceptions\ApiException;

class GoogleAuthProvider extends AbstractSocialAuthProvider
{
    protected function providerKey(): string
    {
        return 'google';
    }

    protected function identityColumn(): string
    {
        return 'google_id';
    }

    protected function verifyToken(string $token): array
    {
        if ($parsed = $this->parseTestToken($token)) {
            return $parsed;
        }

        // Stub until Google client ID + tokeninfo / JWKS verification is wired.
        // Expected production path: verify Google ID token against audience = GOOGLE_CLIENT_ID.
        if (config('rush.auth.allow_unsigned_social_tokens')) {
            $hash = hash('sha256', $token);

            return [
                'provider_user_id' => 'google_'.$hash,
                'email' => null,
                'name' => null,
            ];
        }

        throw ApiException::make(
            'token_invalid',
            'Google token verification is not configured. Use a test token (test:{id}:{email}:{name}) in local/testing.',
            401,
        );
    }
}
