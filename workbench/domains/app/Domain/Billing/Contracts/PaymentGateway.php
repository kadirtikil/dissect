<?php

namespace Workbench\Domains\Domain\Billing\Contracts;

interface PaymentGateway
{
    public function charge(int $cents): bool;
}
