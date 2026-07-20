<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The inverse side of a HasOne. */
class Profile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['preferences' => 'array', 'verified_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
