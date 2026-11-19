<?php

namespace Workbench\Domains\Domain\Billing\Http\Controllers;

use Workbench\Domains\Domain\Billing\Jobs\SendInvoice;
use Workbench\Domains\Domain\Billing\Models\Invoice;

class InvoiceController
{
    public function index(): array
    {
        return Invoice::query()->get()->all();
    }

    public function send(Invoice $invoice): array
    {
        SendInvoice::dispatch($invoice);

        return ['queued' => true];
    }
}
