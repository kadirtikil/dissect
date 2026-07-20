<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** Source of the HasManyThrough case. */
class Country extends Model
{
    protected $guarded = [];

    public function authors(): HasMany
    {
        return $this->hasMany(Author::class);
    }

    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, Author::class);
    }
}
