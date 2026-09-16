<?php

namespace Workbench\App\Services;

use Workbench\App\Contracts\SearchIndex;

/**
 * A second implementation of the same contract, bound by the deferred
 * provider.
 *
 * Nothing resolves it in a request; it is here so `provides()` has something to
 * promise that no other provider also binds.
 */
class NullSearchIndex implements SearchIndex
{
    public function index(string $document): void
    {
        //
    }
}
