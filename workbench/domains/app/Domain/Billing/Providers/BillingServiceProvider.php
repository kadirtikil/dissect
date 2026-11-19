<?php

namespace Workbench\Domains\Domain\Billing\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\Domains\Domain\Billing\Contracts\PaymentGateway;
use Workbench\Domains\Domain\Billing\Services\FakeGateway;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, FakeGateway::class);
    }
}
