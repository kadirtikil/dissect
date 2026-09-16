<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Services\HtmlReportRenderer;

/**
 * What RegistersSiblingServiceProvider registers.
 *
 * Deliberately shallow — one binding, so a test can tell the difference between
 * following the registration edge and stopping at it.
 */
class SiblingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('workbench.renderer', HtmlReportRenderer::class);
    }
}
