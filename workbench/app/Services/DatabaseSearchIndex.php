<?php

namespace Workbench\App\Services;

use Workbench\App\Contracts\SearchIndex;

/**
 * The concrete a plain `bind()` resolves to.
 *
 * Its constructor takes a class of its own, which is the hop a flat list of
 * bindings cannot show: the provider names this class, and only its signature
 * says the index also needs a transcoder.
 */
class DatabaseSearchIndex implements SearchIndex
{
    public function __construct(
        protected FfmpegTranscoder $transcoder,
    ) {}

    public function index(string $document): void
    {
        //
    }
}
