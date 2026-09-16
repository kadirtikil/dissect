<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Contracts\SearchIndex;
use Workbench\App\Services\FfmpegTranscoder;

/**
 * A provider that resolves things instead of binding them.
 *
 * Three spellings of the same act — `make()`, `resolve()` and the `app()`
 * helper — and all of them in `boot()`, where a provider is allowed to reach
 * into the container because everything else has already registered.
 *
 * Worth distinguishing from a binding in the tree: this provider does not
 * decide what the class is, it only needs one.
 */
class ResolvingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(SearchIndex::class);

        resolve(FfmpegTranscoder::class);

        app(SearchIndex::class);
    }
}
