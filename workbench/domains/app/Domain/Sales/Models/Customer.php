<?php

namespace Workbench\Domains\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workbench\Domains\Domain\Billing\Models\Invoice;

class Customer extends Model
{
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /** Across the boundary the other way. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
