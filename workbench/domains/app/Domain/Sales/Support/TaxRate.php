<?php

namespace Workbench\Domains\Domain\Sales\Support;

use Workbench\Domains\Attributes\Domain;

/**
 * An override that has gone stale: the attribute says Billing, the folder
 * says Sales. dissect reports it rather than believing either.
 */
#[Domain('Billing')]
class TaxRate
{
    public const STANDARD = 19;
}
