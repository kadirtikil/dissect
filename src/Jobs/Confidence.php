<?php

namespace KdrDev\Dissect\Jobs;

/**
 * How much of what is reported about a job was actually established.
 *
 * The same ladder the request and response shapes are graded on, kept as a
 * small object because a job's grade is decided in several places at once —
 * `backoff()` and `middleware()` are read independently, and the honest answer
 * is the weakest of them. Threading a string through those calls and
 * remembering to keep the lowest is the kind of bookkeeping that quietly stops
 * being done.
 *
 * It only ever moves downwards.
 */
class Confidence
{
    /** Weakest last — the index is the ordering. */
    protected const LADDER = ['certain', 'inferred', 'unknown'];

    protected int $level = 0;

    /** Read from source rather than produced by the framework. */
    public function inferred(): void
    {
        $this->level = max($this->level, 1);
    }

    /** The declaration is there and its value could not be read. */
    public function unknown(): void
    {
        $this->level = max($this->level, 2);
    }

    public function value(): string
    {
        return self::LADDER[$this->level];
    }
}
