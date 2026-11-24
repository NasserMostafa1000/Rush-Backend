<?php

namespace App\Business\Auth\Providers;

use App\Business\Exceptions\ApiException;

class AppleAuthProvider extends AbstractSocialAuthProvider
{
    protected function providerKey(): string
    {
        return 'apple';
    }

    protected function identityColumn(): string
    {
        return 'apple_id';
    }

    protected function verifyToken(string $token): array
    {
        if ($parsed = $this->parseTestToken($token)) {
            return $parsed;
        }

        if (config('rush.auth.allow_unsigned_social_tokens')) {
            $hash = hash('sha256', $token);

            return [
                'provider_user_id' => 'apple_'.$hash,
                'email' => null,
                'name' => null,
            ];
        }

        throw ApiException::make(
            'token_invalid',
            'Apple token verification is not configured. Use a test token (test:{id}:{email}:{name}) in local/testing.',
            401,
        );
    }
}
