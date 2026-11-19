<?php

namespace Workbench\Domains\Domain\Billing\Services;

use Workbench\Domains\Domain\Billing\Contracts\PaymentGateway;
use Workbench\Domains\Support\Clock;

class FakeGateway implements PaymentGateway
{
    public function __construct(protected Clock $clock) {}

    public function charge(int $cents): bool
    {
        return $cents > 0;
    }
}
