<?php

namespace KdrDev\Dissect\Changes;

/**
 * How much a change can hurt whoever already depends on the thing changed.
 *
 * - **breaking**: something that worked stops working — a route, column or
 *   binding gone, a request field now required, a response field gone, a
 *   route renamed (every `route('old.name')` throws).
 * - **risky**: it may well be fine, but someone should look — a type or a
 *   rule changed, middleware added or taken off, a queue moved.
 * - **additive**: nothing that worked stops — something new, or something
 *   loosened.
 *
 * Without a rule below: new is additive, gone is breaking, different is
 * risky. The rules are the cases where that default reads a change wrong.
 */
final class Severity
{
    public const BREAKING = 'breaking';

    public const RISKY = 'risky';

    public const ADDITIVE = 'additive';

    /** Worst first. */
    public const ORDER = [self::BREAKING, self::RISKY, self::ADDITIVE];

    /** A whole item new or gone. */
    public static function ofItem(string $change): string
    {
        return $change === 'added' ? self::ADDITIVE : self::BREAKING;
    }

    /**
     * One fact of a changed item.
     *
     * @param  array{path: array<int, string>, change: string, before: mixed, after: mixed}  $fact
     */
    public static function ofFact(string $part, array $fact): string
    {
        foreach (self::rules() as [$rulePart, $pattern, $change, $severity]) {
            if ($rulePart === $part && ($change === '*' || $change === $fact['change']) && self::matches($pattern, $fact['path'])) {
                return is_string($severity) ? $severity : $severity($fact['before'], $fact['after']);
            }
        }

        return match ($fact['change']) {
            'added' => self::ADDITIVE,
            'removed' => self::BREAKING,
            default => self::RISKY,
        };
    }

    /** The worst of several. */
    public static function worst(array $severities): string
    {
        foreach (self::ORDER as $severity) {
            if (in_array($severity, $severities, true)) {
                return $severity;
            }
        }

        return self::ADDITIVE;
    }

    /**
     * Part, path (`*` is any one name), change, and the severity or a
     * function of the value before and after.
     *
     * @return array<int, array{string, array<int, string>, string, string|callable(mixed, mixed): string}>
     */
    protected static function rules(): array
    {
        return [
            // Every query against the old table name fails.
            ['models', ['table'], 'changed', self::BREAKING],
            // A column hidden drops out of every JSON response the model is in.
            ['models', ['columns', '*', 'hidden'], 'changed', fn ($before, $after) => $after === true ? self::BREAKING : self::RISKY],

            // Every route('old.name') throws; naming an unnamed route breaks nothing.
            ['routes', ['name'], 'changed', fn ($before, $after) => $before === null ? self::ADDITIVE : self::BREAKING],
            // Auth added locks clients out; auth taken off lets others in. Either way, look.
            ['routes', ['middleware'], '*', self::RISKY],
            ['routes', ['parameters', '*'], 'added', fn ($before, $after) => ($after['optional'] ?? false) ? self::ADDITIVE : self::BREAKING],
            // A client that does not send a newly required field gets a 422.
            ['routes', ['request', 'fields', '*'], 'added', fn ($before, $after) => ($after['required'] ?? false) ? self::BREAKING : self::ADDITIVE],
            // What a client sends for it is now ignored.
            ['routes', ['request', 'fields', '*'], 'removed', self::RISKY],
            ['routes', ['request', 'fields', '*', 'required'], 'changed', fn ($before, $after) => $after === true ? self::BREAKING : self::ADDITIVE],
            ['routes', ['request', 'fields', '*', 'rules'], '*', self::RISKY],
            ['routes', ['response', 'status'], 'changed', self::BREAKING],
            ['routes', ['response', 'fields', '*', 'conditional'], 'changed', fn ($before, $after) => $after === true ? self::RISKY : self::ADDITIVE],

            // Jobs already on the queue were serialised without it.
            ['jobs', ['payload', '*'], 'added', fn ($before, $after) => ($after['optional'] ?? false) ? self::ADDITIVE : self::RISKY],
            ['jobs', ['payload', '*'], 'removed', self::RISKY],
            ['jobs', ['dispatched_by'], 'added', self::ADDITIVE],
            ['jobs', ['dispatched_by'], 'removed', self::RISKY],
            ['jobs', ['middleware'], '*', self::RISKY],
            ['jobs', ['events'], '*', self::RISKY],

            ['providers', ['provides'], 'removed', self::RISKY],
            ['providers', ['side_effects', '*'], '*', self::RISKY],
        ];
    }

    /**
     * @param  array<int, string>  $pattern
     * @param  array<int, string>  $path
     */
    protected static function matches(array $pattern, array $path): bool
    {
        if (count($pattern) !== count($path)) {
            return false;
        }

        foreach ($pattern as $i => $segment) {
            if ($segment !== '*' && $segment !== $path[$i]) {
                return false;
            }
        }

        return true;
    }
}
