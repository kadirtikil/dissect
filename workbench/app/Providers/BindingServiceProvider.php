<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Contracts\SearchIndex;
use Workbench\App\Contracts\Transcoder;
use Workbench\App\Services\DatabaseSearchIndex;
use Workbench\App\Services\FfmpegTranscoder;

/**
 * The four plain container calls, each with a literal class-string.
 *
 * This is the shape the reader should be able to describe with no guessing at
 * all: every argument is a `::class` constant, so an edge from this provider to
 * each concrete is a fact and not an inference.
 *
 * `instance()` takes an object rather than a class name, which is the one of
 * the four where the concrete is only knowable from the `new` expression.
 */
class BindingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SearchIndex::class, DatabaseSearchIndex::class);

        $this->app->singleton(Transcoder::class, FfmpegTranscoder::class);

        $this->app->scoped('workbench.scoped-index', DatabaseSearchIndex::class);

        $this->app->instance('workbench.transcoder', new FfmpegTranscoder);
    }
}
