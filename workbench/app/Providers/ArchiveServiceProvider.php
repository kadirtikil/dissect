<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Contracts\ReportRenderer;
use Workbench\App\Services\HtmlReportRenderer;
use Workbench\App\Services\ReportArchive;

/**
 * One binding whose real weight is in the constructors behind it.
 *
 * The provider file names a single class. Everything else in its tree — the
 * storage, the index that points back at the storage, the framework filesystem
 * and the parameters nobody could name — is only in the signatures, which is
 * the part of a provider a flat list of bindings cannot show.
 *
 * The renderer contract is bound here too, so the archive's type-hint on it
 * resolves through this provider's own decision rather than stopping.
 */
class ArchiveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportArchive::class);

        $this->app->bind(ReportRenderer::class, HtmlReportRenderer::class);
    }
}
