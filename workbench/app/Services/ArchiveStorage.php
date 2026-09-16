<?php

namespace Workbench\App\Services;

use Illuminate\Filesystem\Filesystem;

/**
 * The middle of the chain: one framework class, one of the app's own.
 *
 * The filesystem is where the walk stops — it is drawn, and its own
 * constructor is not read. The index is where it continues, and where it comes
 * back round.
 */
class ArchiveStorage
{
    public function __construct(
        protected Filesystem $files,
        protected ArchiveIndex $index,
    ) {}
}
