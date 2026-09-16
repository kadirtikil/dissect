<?php

namespace Workbench\App\Services;

use Workbench\App\Contracts\ReportRenderer;

/**
 * What the contextual binding gives, and only to ReportMailer.
 */
class PdfReportRenderer implements ReportRenderer
{
    public function render(string $report): string
    {
        return $report;
    }
}
