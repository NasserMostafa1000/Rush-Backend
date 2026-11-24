<?php

namespace Tests\Unit\Auth;

use App\Business\Auth\Factories\AuthProviderFactory;
use App\Business\Auth\Providers\AppleAuthProvider;
use App\Business\Auth\Providers\GoogleAuthProvider;
use App\Business\Auth\Providers\PhoneAuthProvider;
use App\Business\Exceptions\ApiException;
use Tests\TestCase;

class AuthProviderFactoryTest extends TestCase
{
    public function test_factory_resolves_providers_via_container(): void
    {
        $factory = $this->app->make(AuthProviderFactory::class);

        $this->assertInstanceOf(GoogleAuthProvider::class, $factory->make('google'));
        $this->assertInstanceOf(AppleAuthProvider::class, $factory->make('apple'));
        $this->assertInstanceOf(PhoneAuthProvider::class, $factory->make('phone'));
    }

    public function test_factory_rejects_unknown_provider(): void
    {
        $this->expectException(ApiException::class);
        $this->app->make(AuthProviderFactory::class)->make('twitter');
    }
}
