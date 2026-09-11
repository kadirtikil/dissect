<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Workbench\App\Models\Author;

/**
 * Dispatched from two places onto two different queues.
 *
 * There is no single right answer to "which queue does this run on", and the
 * export says `mixed` rather than picking whichever site it read first.
 */
class SyncAuthorProfile implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(public Author $author) {}

    public function handle(): void
    {
        //
    }
}
