<?php

namespace Workbench\Domains\Domain\Sales\Http\Controllers;

use Workbench\Domains\Domain\Sales\Models\Quote;

class QuoteController
{
    public function index(): array
    {
        return Quote::query()->get()->all();
    }
}
