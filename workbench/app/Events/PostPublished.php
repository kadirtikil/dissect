<?php

namespace Workbench\App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Workbench\App\Models\Post;

class PostPublished
{
    use Dispatchable;

    public function __construct(public Post $post) {}
}
