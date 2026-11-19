<?php

namespace KdrDev\Dissect\Architecture;

/**
 * One domain (a bounded context): its name, where it was learned from, and
 * the directories (or single files) that belong to it.
 *
 * Roots are absolute and resolved, because the question asked of them is
 * "does this file sit under one of you", and reflection hands back resolved
 * paths.
 */
final class Context
{
    /**
     * @param  array<int, ContextSource>  $sources
     * @param  array<int, string>  $roots
     */
    public function __construct(
        public readonly string $name,
        public readonly array $sources,
        public readonly array $roots,
    ) {}

    /**
     * The same domain, found a second way.
     *
     * Two detectors can name one domain — a `Billing` module and a `Billing`
     * folder under `app/Domain` — and that is still one domain with two homes.
     */
    public function merge(self $other): self
    {
        return new self(
            $this->name,
            array_values(array_unique([...$this->sources, ...$other->sources], SORT_REGULAR)),
            array_values(array_unique([...$this->roots, ...$other->roots])),
        );
    }

    /** Whether a file sits in one of this domain's roots, or is one. */
    public function owns(string $file): bool
    {
        return $this->depth($file) !== null;
    }

    /**
     * How long the matching root is, or null when none matches.
     *
     * The longest root wins between two domains, so `app/Models/Billing`
     * claims an invoice before a wider `app/Models` mapping does.
     */
    public function depth(string $file): ?int
    {
        $best = null;

        foreach ($this->roots as $root) {
            if ($file === $root || str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
                $best = max($best ?? 0, strlen($root));
            }
        }

        return $best;
    }
}
