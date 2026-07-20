<?php

namespace KdrDev\Dissect\Types;

use KdrDev\Dissect\Contracts\TypeNormalizer;

/**
 * Shared parsing for the concrete drivers.
 *
 * Every database reports types in the same broad shape — a base name, optional
 * parameters in brackets, and optional trailing modifiers — so only the lookup
 * table differs between drivers. Subclasses supply that table and, where a type
 * needs real logic (MySQL's tinyint(1), for example), override `resolve()`.
 *
 * Deliberately free of Illuminate imports: these classes are pure functions over
 * strings, so they can be exercised without booting a framework or a database.
 */
abstract class BaseTypeNormalizer implements TypeNormalizer
{
    /**
     * base type name => [display label, kind, keep parameters in the label?]
     *
     * Parameters are kept where they carry meaning a developer scans for
     * (varchar(255)) and dropped where they are noise (timestamp(0)).
     *
     * @return array<string, array{0: string, 1: ColumnKind, 2: bool}>
     */
    abstract protected function map(): array;

    public function normalize(string $native): NormalizedType
    {
        $native = trim($native);

        [$base, $params] = $this->split($native);

        return $this->resolve($native, $base, $params)
            ?? $this->lookup($native, $base, $params)
            ?? new NormalizedType($native, $native, ColumnKind::Unknown);
    }

    /**
     * Hook for types that need more than a table lookup. Returning null falls
     * through to the map.
     */
    protected function resolve(string $native, string $base, ?string $params): ?NormalizedType
    {
        return null;
    }

    protected function lookup(string $native, string $base, ?string $params): ?NormalizedType
    {
        $entry = $this->map()[$base] ?? null;

        if ($entry === null) {
            return null;
        }

        [$label, $kind, $keepParams] = $entry;

        if ($keepParams && $params !== null && $params !== '') {
            $label .= '('.$params.')';
        }

        return new NormalizedType($native, $label, $kind);
    }

    /**
     * Splits "character varying(255)" into ["character varying", "255"].
     *
     * Trailing modifiers are folded into the base name so that Postgres'
     * "timestamp(0) without time zone" still matches a single map entry.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function split(string $native): array
    {
        $lower = strtolower($native);

        if (! preg_match('/^([^(]+?)\s*\(([^)]*)\)\s*(.*)$/', $lower, $matches)) {
            return [$this->normaliseWhitespace($lower), null];
        }

        $base = $matches[1];
        $params = $matches[2];
        $suffix = trim($matches[3]);

        if ($suffix !== '') {
            $base .= ' '.$suffix;
        }

        return [$this->normaliseWhitespace($base), $params];
    }

    protected function normaliseWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
