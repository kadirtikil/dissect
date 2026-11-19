<?php

namespace Workbench\Domains\Domain\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Workbench\Domains\Domain\Billing\Models\Invoice;

class SendInvoice implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public Invoice $invoice) {}

    public function handle(): void {}
}
