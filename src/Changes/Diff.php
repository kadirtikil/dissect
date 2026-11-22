<?php

namespace KdrDev\Dissect\Changes;

/**
 * What changed between two snapshots, item by item and fact by fact.
 *
 * An item that is new or gone is one change, not one per fact: a new model is
 * "+ model Refund", not a line for each of its columns. An item in both is
 * compared fact by fact, and each difference is one entry with the path to it:
 *
 * - a named thing inside an item that is new or gone (`columns.refunded_at`);
 * - a plain value that differs (`columns.total.nullable`, false → true);
 * - a value added to or taken from a list (`middleware`, + `auth:sanctum`).
 *
 * Lists are compared as sets. Middleware, rules and provides are read for
 * what is in them; the order they happen to be listed in is not a change.
 *
 * A fact missing on one side is an empty map or list when the other side has
 * one, since a snapshot leaves empty maps out: a model's first relation is
 * "+ relation customer", not "relations: none → {…}".
 */
final class Diff
{
    /** The facts that hold things by name: a column, a field, a binding. */
    public const NAMED = ['columns', 'relations', 'parameters', 'fields', 'payload', 'edges', 'side_effects'];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<int, array{part: string, id: string, change: string, facts: array<int, array{path: array<int, string>, change: string, before: mixed, after: mixed}>}>
     */
    public static function between(array $before, array $after): array
    {
        $changes = [];

        foreach (array_keys(Snapshot::PARTS) as $part) {
            $old = (array) ($before[$part] ?? []);
            $new = (array) ($after[$part] ?? []);

            $ids = array_unique([...array_map('strval', array_keys($old)), ...array_map('strval', array_keys($new))]);
            sort($ids, SORT_STRING);

            foreach ($ids as $id) {
                if (! array_key_exists($id, $old)) {
                    $changes[] = ['part' => $part, 'id' => $id, 'change' => 'added', 'facts' => []];
                } elseif (! array_key_exists($id, $new)) {
                    $changes[] = ['part' => $part, 'id' => $id, 'change' => 'removed', 'facts' => []];
                } elseif (($facts = self::facts($old[$id], $new[$id], [])) !== []) {
                    $changes[] = ['part' => $part, 'id' => $id, 'change' => 'changed', 'facts' => $facts];
                }
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, string>  $path
     * @return array<int, array{path: array<int, string>, change: string, before: mixed, after: mixed}>
     */
    protected static function facts(mixed $before, mixed $after, array $path): array
    {
        if ($before === $after) {
            return [];
        }

        if (is_array($before) && is_array($after)) {
            return self::isList($before) && self::isList($after)
                ? self::listed($before, $after, $path)
                : self::mapped($before, $after, $path);
        }

        return [['path' => $path, 'change' => 'changed', 'before' => $before, 'after' => $after]];
    }

    /**
     * @param  array<int|string, mixed>  $before
     * @param  array<int|string, mixed>  $after
     * @param  array<int, string>  $path
     */
    protected static function mapped(array $before, array $after, array $path): array
    {
        $facts = [];
        $keys = array_unique([...array_map('strval', array_keys($before)), ...array_map('strval', array_keys($after))]);
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $at = [...$path, $key];
            $had = array_key_exists($key, $before);
            $has = array_key_exists($key, $after);

            if ($has && $had) {
                array_push($facts, ...self::facts($before[$key], $after[$key], $at));
            } elseif (in_array(end($path), self::NAMED, true)) {
                // A named thing in a map of them: new or gone as a whole.
                $facts[] = $had
                    ? ['path' => $at, 'change' => 'removed', 'before' => $before[$key], 'after' => null]
                    : ['path' => $at, 'change' => 'added', 'before' => null, 'after' => $after[$key]];
            } elseif (is_array($after[$key] ?? null) || is_array($before[$key] ?? null)) {
                // A map or list left out of one side, because it was empty.
                array_push($facts, ...self::facts($before[$key] ?? [], $after[$key] ?? [], $at));
            } else {
                $facts[] = ['path' => $at, 'change' => 'changed', 'before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
            }
        }

        return $facts;
    }

    /**
     * @param  array<int, mixed>  $before
     * @param  array<int, mixed>  $after
     * @param  array<int, string>  $path
     */
    protected static function listed(array $before, array $after, array $path): array
    {
        $key = fn (mixed $value) => is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value);
        $old = array_combine(array_map($key, $before), $before) ?: [];
        $new = array_combine(array_map($key, $after), $after) ?: [];
        $facts = [];

        foreach (array_diff_key($old, $new) as $value) {
            $facts[] = ['path' => $path, 'change' => 'removed', 'before' => $value, 'after' => null];
        }

        foreach (array_diff_key($new, $old) as $value) {
            $facts[] = ['path' => $path, 'change' => 'added', 'before' => null, 'after' => $value];
        }

        return $facts;
    }

    /** An empty array is a list as much as a map, and fits either. */
    protected static function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
