<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Contracts\SearchIndex;
use Workbench\App\Contracts\Transcoder;
use Workbench\App\Services\DatabaseSearchIndex;
use Workbench\App\Services\FfmpegTranscoder;

/**
 * Bindings declared as properties rather than calls.
 *
 * `$bindings` and `$singletons` are registered by the framework, not by this
 * class, so a reader that only walks `register()` finds an empty provider and
 * reports nothing — the same blind spot as a job declaring `public $queue`
 * instead of chaining `onQueue()`.
 */
class PropertyBindingServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public $bindings = [
        SearchIndex::class => DatabaseSearchIndex::class,
    ];

    /**
     * @var array<class-string, class-string>
     */
    public $singletons = [
        Transcoder::class => FfmpegTranscoder::class,
    ];
}
