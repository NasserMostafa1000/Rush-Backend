<?php

namespace App\Business\Auth\Factories;

use App\Business\Auth\Contracts\AuthProviderInterface;
use App\Business\Auth\Providers\AppleAuthProvider;
use App\Business\Auth\Providers\GoogleAuthProvider;
use App\Business\Auth\Providers\PhoneAuthProvider;
use App\Business\Exceptions\ApiException;
use Illuminate\Contracts\Container\Container;

class AuthProviderFactory
{
    public function __construct(private readonly Container $container)
    {
    }

    public function make(string $loginProviderName): AuthProviderInterface
    {
        $key = strtolower(trim($loginProviderName));

        return match ($key) {
            'google' => $this->container->make(GoogleAuthProvider::class),
            'apple' => $this->container->make(AppleAuthProvider::class),
            'phone' => $this->container->make(PhoneAuthProvider::class),
            default => throw ApiException::make(
                'provider_invalid',
                'login_provider_name must be google, apple, or phone.',
                422,
            ),
        };
    }
}
