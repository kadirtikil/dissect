<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Contracts\SearchIndex;
use Workbench\App\Contracts\Transcoder;
use Workbench\App\Services\DatabaseSearchIndex;
use Workbench\App\Services\FfmpegTranscoder;

/**
 * The provider a static reader cannot fully describe, on purpose.
 *
 * Nothing here is a literal class-string at the call site: one binding comes
 * from a loop over an array, one from a property, and one from a helper method.
 * All three are ordinary ways to write a provider, and all three are invisible
 * to a parser that only matches `bind(X::class, Y::class)`.
 *
 * It exists so the incomplete case is a fixture with an assertion rather than a
 * surprise in somebody's application: a tree for this provider should say it
 * could not read everything instead of quietly drawing an empty one.
 */
class OpaqueServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $map = [
        SearchIndex::class => DatabaseSearchIndex::class,
        Transcoder::class => FfmpegTranscoder::class,
    ];

    public function register(): void
    {
        foreach ($this->map as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }

        $this->bindTranscoder();
    }

    protected function bindTranscoder(): void
    {
        $this->app->singleton('workbench.opaque-transcoder', fn () => new FfmpegTranscoder);
    }
}
