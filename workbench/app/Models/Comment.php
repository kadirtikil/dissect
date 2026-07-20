<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    protected $guarded = [];

    /**
     * A MorphTo has no single target, so the exporter reports it pointing at
     * its own model — the self-loop case the layout has to survive.
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
