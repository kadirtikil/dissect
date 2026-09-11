<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * An abstract base job. Real code, but not a thing that can sit on a queue —
 * discovery has to skip it, or the list grows a row no dispatch site can name.
 */
abstract class BaseImport implements ShouldQueue
{
    use Dispatchable, Queueable;

    abstract public function handle(): void;
}
