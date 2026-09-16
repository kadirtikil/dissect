<?php

namespace Workbench\App\Services;

use Workbench\App\Contracts\ReportRenderer;

/**
 * What everybody else gets for the same contract.
 *
 * The default binding the contextual one overrides — without it, `when()`
 * would be the only edge to the renderer and the override would look like the
 * only answer.
 */
class HtmlReportRenderer implements ReportRenderer
{
    public function render(string $report): string
    {
        return $report;
    }
}
