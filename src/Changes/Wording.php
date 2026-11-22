<?php

namespace KdrDev\Dissect\Changes;

/**
 * A change in words, one line each, the same for the terminal, an agent and
 * the page:
 *
 *     + column refunded_at (datetime, nullable)
 *     ~ column total: now nullable
 *     ~ status 200 → 201
 *     + middleware auth:sanctum
 *     + rule min:1 on request field total
 *
 * `+` is new, `-` gone, `~` different. Class names are shortened to the last
 * part, since the item they belong to already says where they are.
 */
final class Wording
{
    /** What each part calls one of its items. */
    public const KINDS = ['models' => 'model', 'routes' => 'route', 'jobs' => 'job', 'providers' => 'provider'];

    /** What a map of named things calls one of them. */
    protected const THINGS = [
        'columns' => 'column',
        'relations' => 'relation',
        'parameters' => 'parameter',
        'payload' => 'argument',
        'edges' => 'binding',
        'side_effects' => 'side effect',
    ];

    /** A fact's name, where its key does not read as one. */
    protected const FACTS = [
        'context' => 'domain',
        'dispatched_by' => 'dispatched by',
        'events' => 'event',
        'rules' => 'rule',
        'maxExceptions' => 'max exceptions',
        'retryUntil' => 'retry until',
        'afterCommit' => 'after commit',
        'request source' => 'request',
        'response source' => 'response',
        'response status' => 'status',
        'keys source' => 'key on this side',
        'keys target' => 'key on the other side',
        'keys via' => 'through',
    ];

    /** A route's id as a reader writes it: `GET|HEAD:api/x` is `GET|HEAD api/x`. */
    public static function route(string $id): string
    {
        return preg_replace('/:/', ' ', $id, 1) ?? $id;
    }

    /** `+ model Refund`, `- route GET api/legacy`, `~ job SendInvoice`. */
    public static function item(string $part, string $name, string $change): string
    {
        return self::sign($change).' '.self::KINDS[$part].' '.$name;
    }

    /** @param  array{path: array<int, string>, change: string, before: mixed, after: mixed}  $fact */
    public static function fact(array $fact): string
    {
        [$thing, $rest] = self::thing($fact['path']);
        $sign = self::sign($fact['change']);

        // A named thing new or gone as a whole.
        if ($thing !== null && $rest === [] && $fact['change'] !== 'changed') {
            $value = $fact['change'] === 'added' ? $fact['after'] : $fact['before'];
            $detail = $fact['change'] === 'added' ? self::detail($fact['path'], $value) : '';

            return "{$sign} {$thing}".($detail === '' ? '' : " ({$detail})");
        }

        $name = self::factName($rest);
        $on = $thing === null ? '' : " on {$thing}";

        // A value added to or taken from a list.
        if ($fact['change'] !== 'changed') {
            return "{$sign} {$name} ".self::value($fact['change'] === 'added' ? $fact['after'] : $fact['before']).$on;
        }

        $subject = $thing === null ? '' : "{$thing}: ";

        if (is_bool($fact['before']) && is_bool($fact['after'])) {
            return "~ {$subject}".($fact['after'] ? 'now' : 'no longer')." {$name}";
        }

        return "~ {$subject}{$name} ".self::value($fact['before']).' → '.self::value($fact['after']);
    }

    /**
     * The named thing a path is about, and the rest of the path within it:
     * `columns.total.nullable` is column total, then `nullable`.
     *
     * @param  array<int, string>  $path
     * @return array{?string, array<int, string>}
     */
    protected static function thing(array $path): array
    {
        if (isset($path[1]) && isset(self::THINGS[$path[0]])) {
            return [self::THINGS[$path[0]].' '.str_replace(' -> ', ' → ', self::short($path[1])), array_slice($path, 2)];
        }

        if (isset($path[2]) && in_array($path[0], ['request', 'response'], true) && $path[1] === 'fields') {
            return ["{$path[0]} field {$path[2]}", array_slice($path, 3)];
        }

        return [null, $path];
    }

    /** @param  array<int, string>  $path */
    protected static function factName(array $path): string
    {
        $joined = implode(' ', $path);

        if (isset(self::FACTS[$joined])) {
            return self::FACTS[$joined];
        }

        // retry.tries reads as "tries", traits.unique as "unique".
        $last = (string) end($path);
        $name = self::FACTS[$last] ?? str_replace('_', ' ', $last);

        return in_array($path[0] ?? null, ['request', 'response'], true) && count($path) === 2
            ? "{$path[0]} {$name}"
            : $name;
    }

    /** What a new thing is, in a word or two. */
    protected static function detail(array $path, mixed $value): string
    {
        if (! is_array($value)) {
            return self::value($value);
        }

        $kind = in_array($path[0], ['request', 'response'], true) ? $path[0].' field' : $path[0];

        $parts = match ($kind) {
            'columns' => [$value['type'] ?? null, ($value['nullable'] ?? false) ? 'nullable' : null],
            'relations' => [($value['type'] ?? '?').' '.self::short((string) ($value['target'] ?? '?'))],
            'parameters' => [($value['optional'] ?? false) ? 'optional' : null],
            'payload' => [isset($value['type']) ? self::short((string) $value['type']) : null, ($value['optional'] ?? false) ? 'optional' : null],
            'request field' => [$value['type'] ?? null, ($value['required'] ?? false) ? 'required' : null],
            'response field' => [$value['kind'] ?? null, ($value['conditional'] ?? false) ? 'conditional' : null],
            'edges' => [$value['kind'] ?? null],
            default => [],
        };

        return implode(', ', array_filter($parts, fn ($part) => $part !== null && $part !== ''));
    }

    protected static function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'none',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => self::short(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '?'),
            default => self::short((string) $value),
        };
    }

    /** Every namespaced class name in a string, as its last part. */
    protected static function short(string $text): string
    {
        return preg_replace('/(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+([A-Za-z_][A-Za-z0-9_]*)/', '$1', $text) ?? $text;
    }

    protected static function sign(string $change): string
    {
        return match ($change) {
            'added' => '+',
            'removed' => '-',
            default => '~',
        };
    }
}
