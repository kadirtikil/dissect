<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A job that says nothing about itself: no queue, no tries, no payload.
 *
 * Everything known about where it runs comes from the dispatch site, which
 * chains `->onQueue('maintenance')` onto it — and that site is inside a route
 * closure, so it also covers the half of the route join that has no controller
 * class to match on.
 */
class PruneComments implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(): void
    {
        //
    }
}
