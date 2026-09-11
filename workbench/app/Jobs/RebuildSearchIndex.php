<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The job nothing dispatches.
 *
 * Dead code or dispatched dynamically — the surface cannot tell which, and says
 * so rather than choosing. It is also the `retryUntil()` case: a deadline
 * computed at queue time, so there is no value to report, only the fact that
 * `tries` is not the whole story.
 */
class RebuildSearchIndex implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onConnection('redis');
    }

    public function retryUntil(): Carbon
    {
        return now()->addHour();
    }

    public function handle(): void
    {
        //
    }
}
