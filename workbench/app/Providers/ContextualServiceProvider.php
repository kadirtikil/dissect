<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Contracts\ReportRenderer;
use Workbench\App\Services\HtmlReportRenderer;
use Workbench\App\Services\PdfReportRenderer;
use Workbench\App\Services\ReportMailer;

/**
 * A contextual binding beside the default it overrides.
 *
 * `when()->needs()->give()` is a chain of three calls carrying three classes,
 * so reading it means following the method chain rather than one call's
 * arguments — and the edge it produces has a consumer as well as a contract
 * and a concrete.
 */
class ContextualServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReportRenderer::class, HtmlReportRenderer::class);

        $this->app->when(ReportMailer::class)
            ->needs(ReportRenderer::class)
            ->give(PdfReportRenderer::class);
    }
}
