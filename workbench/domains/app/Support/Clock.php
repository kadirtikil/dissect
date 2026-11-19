<?php

namespace Workbench\Domains\Support;

/**
 * In no domain at all, and listed as unassigned rather than guessed at.
 */
class Clock
{
    public function now(): int
    {
        return time();
    }
}
