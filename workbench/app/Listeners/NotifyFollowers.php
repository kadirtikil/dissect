<?php

namespace Workbench\App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Workbench\App\Events\PostPublished;

/**
 * A queued listener.
 *
 * The shape with no marker of its own: what makes it a listener rather than a
 * job is that something registered it against an event, which only the
 * dispatcher knows. It is also the one shape that *can* declare `public $queue`
 * — nothing in its ancestry claims that property — so it covers the branch a
 * job using `Queueable` cannot reach.
 */
class NotifyFollowers implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'listeners';

    public $tries = 5;

    public $backoff = 30;

    public function handle(PostPublished $event): void
    {
        //
    }
}
