<?php

namespace Workbench\App\Services;

/**
 * Depends on the storage that depends on it.
 *
 * The container would never resolve this pair, and it does not need to for a
 * reader to walk into it: the cycle is a fixture so the walk is proven to draw
 * the arrow back and stop, rather than to go round until something runs out.
 */
class ArchiveIndex
{
    public function __construct(
        protected ArchiveStorage $storage,
    ) {}
}
