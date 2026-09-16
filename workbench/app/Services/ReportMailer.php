<?php

namespace Workbench\App\Services;

use Workbench\App\Contracts\ReportRenderer;

/**
 * The asking half of the contextual binding.
 *
 * It type-hints the contract like anything else; what it gets is decided by the
 * `when(ReportMailer::class)` clause in ContextualServiceProvider, which is a
 * fact about the provider rather than about this class.
 */
class ReportMailer
{
    public function __construct(
        protected ReportRenderer $renderer,
    ) {}
}
