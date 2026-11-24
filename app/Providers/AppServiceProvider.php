<?php

namespace App\Providers;

use App\Business\Auth\Factories\AuthProviderFactory;
use App\Business\Services\AuthService;
use App\Business\Services\CloudflareR2Service;
use App\Business\Services\DriverOrderService;
use App\Business\Services\DriverService;
use App\Business\Services\LoyaltyService;
use App\Business\Services\NotificationService;
use App\Business\Services\OrderService;
use App\Business\Services\PaymentService;
use App\Business\Services\PromoCodeService;
use App\Business\Services\SavedAddressService;
use App\Business\Services\StripePaymentGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthProviderFactory::class);
        $this->app->singleton(CloudflareR2Service::class);
        $this->app->singleton(DriverService::class);
        $this->app->singleton(DriverOrderService::class);
        $this->app->singleton(StripePaymentGateway::class);
        $this->app->singleton(NotificationService::class);
        $this->app->singleton(LoyaltyService::class);
        $this->app->singleton(PromoCodeService::class);
        $this->app->singleton(PaymentService::class);
        $this->app->singleton(AuthService::class);
        $this->app->singleton(OrderService::class);
        $this->app->singleton(SavedAddressService::class);
    }

    public function boot(): void
    {
        //
    }
}
