<?php

namespace KdrDev\Dissect\Types;

/**
 * A column type expressed three ways.
 *
 * `native` is never discarded: it is what the database actually reported, and a
 * developer chasing a migration issue wants to see it verbatim. `label` is the
 * short form for display, and `kind` is the portable meaning.
 */
final readonly class NormalizedType
{
    public function __construct(
        public string $native,
        public string $label,
        public ColumnKind $kind,
    ) {}

    /** @return array{native: string, label: string, kind: string} */
    public function toArray(): array
    {
        return [
            'native' => $this->native,
            'label' => $this->label,
            'kind' => $this->kind->value,
        ];
    }
}
