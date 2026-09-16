<?php

namespace Workbench\App\Contracts;

/**
 * The needed half of a contextual binding.
 *
 * `when(A)->needs(ReportRenderer)->give(B)` is the one construct where the
 * same contract resolves differently depending on who asked, so the edge
 * carries a third class the other binding shapes do not have.
 */
interface ReportRenderer
{
    public function render(string $report): string;
}
