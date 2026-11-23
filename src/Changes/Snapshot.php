<?php

namespace KdrDev\Dissect\Changes;

use Illuminate\Support\Str;
use KdrDev\Dissect\Support\Surfaces;

/**
 * What the app looks like right now, in a form two moments can be compared in.
 *
 * Each model, route, job and provider is kept under the id the page already
 * uses for it, reduced to the facts a change to the code can move: a model's
 * table, columns and relations; a route's name, action, middleware, input and
 * output; a job's queue, retries and payload; a provider's bindings. Anything
 * that changes without the code changing is left out — when it was read, the
 * machine it was read on (absolute paths), the line a closure starts on, how
 * sure the analysis was — so taking a snapshot twice gives the same bytes, and
 * a committed snapshot's own diff in a pull request reads as what changed.
 *
 * An empty map inside an item is left out rather than written as `[]`, which
 * JSON cannot tell from an empty list. A missing map and an empty one mean
 * the same thing to the diff.
 */
final class Snapshot
{
    /** Bumped whenever a fact is added, removed or reshaped. */
    public const VERSION = 2;

    /** Each part of a snapshot, and the surface it is read from. */
    public const PARTS = ['models' => 'schema', 'routes' => 'routes', 'jobs' => 'jobs', 'providers' => 'providers'];

    public function __construct(protected Surfaces $surfaces) {}

    /** @return array<string, mixed> */
    public function take(): array
    {
        $payloads = [];

        foreach (self::PARTS as $surface) {
            $payloads[$surface] = $this->surfaces->export($surface);
        }

        return self::of($payloads, (array) config('dissect.snapshot_ignore_routes', []));
    }

    /**
     * @param  array<string, array<string, mixed>>  $payloads  Keyed by surface name.
     * @param  array<int, string>  $ignoreRoutes  Patterns for routes to leave out. See leftOut().
     * @return array<string, mixed>
     */
    public static function of(array $payloads, array $ignoreRoutes = []): array
    {
        return [
            'version' => self::VERSION,
            'models' => self::models($payloads['schema'] ?? []),
            'routes' => self::keyed(array_filter($payloads['routes']['routes'] ?? [], fn (array $route) => ! self::leftOut($route, $ignoreRoutes)), self::route(...)),
            'jobs' => self::keyed($payloads['jobs']['jobs'] ?? [], self::job(...)),
            'providers' => self::keyed($payloads['providers']['providers'] ?? [], self::provider(...)),
        ];
    }

    /**
     * A snapshot written earlier, to compare against.
     *
     * One in another format is refused rather than compared: every item
     * would read as changed, and a real change would be lost among them.
     *
     * @return array<string, mixed>
     *
     * @throws UnreadableSnapshot
     */
    public static function read(string $path, string $shown): array
    {
        if (! is_file($path)) {
            throw new UnreadableSnapshot("There is no snapshot at {$shown}. Take one with: php artisan dissect:snapshot");
        }

        $snapshot = json_decode((string) @file_get_contents($path), true);

        if (! is_array($snapshot) || ! isset($snapshot['version']) || array_is_list($snapshot)) {
            throw new UnreadableSnapshot("{$shown} is not a dissect snapshot. Take a new one with: php artisan dissect:snapshot");
        }

        if ($snapshot['version'] !== self::VERSION) {
            throw new UnreadableSnapshot(
                "{$shown} is in snapshot format ".json_encode($snapshot['version']).', and this version of dissect reads format '.self::VERSION
                .'. Comparing across formats would show everything as changed. Take a new one with: php artisan dissect:snapshot'
            );
        }

        return $snapshot;
    }

    /** How many of each part a snapshot holds. @return array<string, int> */
    public static function counts(array $snapshot): array
    {
        $counts = [];

        foreach (array_keys(self::PARTS) as $part) {
            $counts[$part] = count((array) ($snapshot[$part] ?? []));
        }

        return $counts;
    }

    /**
     * The snapshot as it is written to disk: stable and short enough to read.
     *
     * Indented like any JSON, except that a map or list holding only plain
     * values goes on one line. That is one line per column, field, parameter
     * or retry setting, so a committed snapshot's diff in a pull request is
     * one changed line per changed thing, not one per fact.
     */
    public static function encode(array $snapshot): string
    {
        // A part with nothing in it is still a map.
        foreach (array_keys(self::PARTS) as $part) {
            if (($snapshot[$part] ?? []) === []) {
                $snapshot[$part] = (object) [];
            }
        }

        return self::json($snapshot, 0)."\n";
    }

    protected static function json(mixed $value, int $depth): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        if (is_object($value) || ! is_array($value) || $value === []) {
            return json_encode($value, $flags);
        }

        $list = array_is_list($value);
        [$open, $close] = $list ? ['[', ']'] : ['{', '}'];
        $entry = fn (mixed $key, mixed $item, int $depth) => ($list ? '' : json_encode((string) $key, $flags).': ').self::json($item, $depth);

        // The parts and their items always open up; below them, anything
        // made only of plain values stays on one line.
        if ($depth > 2 && array_filter($value, fn (mixed $item) => is_array($item) && ! self::plain($item)) === []) {
            return $open.implode(', ', array_map(fn (mixed $key, mixed $item) => $entry($key, $item, $depth + 1), array_keys($value), $value)).$close;
        }

        $indent = str_repeat('    ', $depth + 1);
        $lines = array_map(fn (mixed $key, mixed $item) => $indent.$entry($key, $item, $depth + 1), array_keys($value), $value);

        return $open."\n".implode(",\n", $lines)."\n".str_repeat('    ', $depth).$close;
    }

    /** A list of plain values, which reads fine on the line it belongs to. */
    protected static function plain(array $value): bool
    {
        return array_is_list($value) && array_filter($value, fn (mixed $item) => is_array($item) || is_object($item)) === [];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    protected static function models(array $schema): array
    {
        $relations = [];

        foreach ($schema['edges'] ?? [] as $edge) {
            $relations[$edge['source']][$edge['name']] = [
                'type' => $edge['type'],
                'target' => $edge['target'],
                'keys' => $edge['keys'] ?? null,
            ];
        }

        return self::keyed($schema['nodes'] ?? [], fn (array $node) => self::compact([
            'table' => $node['table'] ?? null,
            // Only the connection the model names: which one is the default
            // is the machine's business, and differs between a laptop and CI.
            'connection' => $node['connection'] ?? null,
            // Not compared itself. It says which database the native column
            // types below were read from, so the diff knows whether they can
            // be compared at all. See Diff::comparable().
            'driver' => $node['driver'] ?? null,
            'context' => $node['context'] ?? null,
            'columns' => self::sorted(array_column(array_map(fn (array $column) => [
                'name' => $column['name'],
                'facts' => [
                    'type' => $column['type'] ?? null,
                    // The same type said the same way on every database.
                    'kind' => $column['kind'] ?? null,
                    'nullable' => $column['nullable'] ?? null,
                    'unique' => $column['unique'] ?? null,
                    'increments' => $column['increments'] ?? null,
                    'fillable' => $column['fillable'] ?? null,
                    'hidden' => $column['hidden'] ?? null,
                    'cast' => $column['cast'] ?? null,
                    'virtual' => $column['virtual'] ?? null,
                ],
            ], $node['columns'] ?? []), 'facts', 'name')),
            'relations' => self::sorted($relations[$node['id']] ?? []),
        ]) + (isset($node['unreadable']) ? [
            // Its columns could not be read, so it has none here: the diff
            // leaves its columns alone rather than calling every one removed.
            'unreadable' => true,
        ] : []));
    }

    /**
     * Routes registered on some machines and not others: a snapshot taken
     * locally would have them and CI's would not, and every one would read
     * as a route removed.
     *
     * dissect's own page and endpoints, which are there only where the page
     * is switched on, and whatever `dissect.snapshot_ignore_routes` names:
     * by default the tools a laptop runs and CI does not, such as Telescope
     * and Debugbar. A pattern is matched against the route's controller
     * class, its name and its URI, with `*` for anything.
     *
     * Not every route a package registers. The app's own JSON:API routes run
     * on the package's controller, and they are as much its API as any.
     *
     * @param  array<int, string>  $ignore
     */
    protected static function leftOut(array $route, array $ignore): bool
    {
        $class = ltrim((string) ($route['action']['class'] ?? ''), '\\');

        if (str_starts_with($class, 'KdrDev\\Dissect\\')) {
            return true;
        }

        $names = array_filter([$class, $route['name'] ?? null, $route['uri'] ?? null], fn (mixed $name) => is_string($name) && $name !== '');

        foreach ($ignore as $pattern) {
            if (is_string($pattern) && $pattern !== '' && array_filter($names, fn (string $name) => Str::is($pattern, $name)) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    protected static function route(array $route): array
    {
        $action = $route['action'] ?? [];

        return self::compact([
            'name' => $route['name'] ?? null,
            // A closure's label is the line it starts on, which moves whenever
            // anything above it does.
            'action' => match ($action['type'] ?? null) {
                'controller' => ($action['class'] ?? '?').'@'.($action['method'] ?? '__invoke'),
                'closure' => 'closure',
                default => $action['label'] ?? null,
            },
            'middleware' => array_values($route['middleware'] ?? []),
            'context' => $route['context'] ?? null,
            'parameters' => self::sorted(array_column(array_map(fn (array $parameter) => [
                'name' => $parameter['name'],
                'facts' => [
                    'optional' => $parameter['optional'] ?? false,
                    'field' => $parameter['field'] ?? null,
                    'pattern' => $parameter['pattern'] ?? null,
                ],
            ], $route['parameters'] ?? []), 'facts', 'name')),
            'request' => self::compact([
                'source' => $route['request']['source'] ?? null,
                'class' => $route['request']['class'] ?? null,
                'fields' => self::sorted(array_column(array_map(fn (array $field) => [
                    'path' => $field['path'],
                    'facts' => [
                        'type' => $field['type'] ?? null,
                        'required' => $field['required'] ?? false,
                        'rules' => array_values($field['rules'] ?? []),
                    ],
                ], $route['request']['fields'] ?? []), 'facts', 'path')),
            ]),
            'response' => self::compact([
                'source' => $route['response']['source'] ?? null,
                'class' => $route['response']['class'] ?? null,
                'status' => $route['response']['status'] ?? null,
                'fields' => self::sorted(array_column(array_map(fn (array $field) => [
                    'path' => $field['path'],
                    'facts' => [
                        'kind' => $field['kind'] ?? null,
                        'conditional' => $field['conditional'] ?? false,
                    ],
                ], $route['response']['fields'] ?? []), 'facts', 'path')),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    protected static function job(array $job): array
    {
        // Where it is dispatched from, by name, never by line: a line moves
        // with every edit above it. A controller site is its action. One in
        // route-file code is labelled `api.php:25`, and is kept as the file
        // alone: its path can be absolute, and so differ between machines.
        $dispatchedBy = array_values(array_unique(array_map(
            fn (array $site) => (string) preg_replace('/\.php:\d+$/', '.php', (string) ($site['label'] ?? '?')),
            $job['dispatched_by'] ?? [],
        )));
        sort($dispatchedBy);

        return self::compact([
            'kind' => $job['kind'] ?? null,
            'context' => $job['context'] ?? null,
            'queue' => $job['queue'] ?? null,
            'connection' => $job['connection'] ?? null,
            'retry' => $job['retry'] ?? [],
            'traits' => $job['traits'] ?? [],
            'payload' => array_column(array_map(fn (array $parameter) => [
                'name' => $parameter['name'],
                'facts' => [
                    'type' => $parameter['type'] ?? null,
                    'optional' => $parameter['optional'] ?? false,
                    'variadic' => $parameter['variadic'] ?? false,
                    'default' => $parameter['default'] ?? null,
                ],
            ], $job['payload'] ?? []), 'facts', 'name'),
            'middleware' => array_values($job['middleware'] ?? []),
            'events' => array_values($job['events'] ?? []),
            'dispatched_by' => $dispatchedBy,
        ]);
    }

    /** @return array<string, mixed> */
    protected static function provider(array $provider): array
    {
        // What it binds, as "abstract -> concrete": the tree's own edges,
        // without the node details (a node's file is an absolute path). A
        // contextual binding adds who it is for, so `when(ReportMailer)` and
        // a plain bind of the same pair are two bindings, and moving the
        // first to InvoiceMailer is a change.
        $names = self::unresolvedNames($provider['nodes'] ?? []);
        $name = fn (string $id) => $names[$id] ?? $id;
        $kinds = [];

        foreach ($provider['edges'] ?? [] as $edge) {
            $key = $name((string) $edge['source']).' -> '.$name((string) $edge['target'])
                .(isset($edge['consumer']) ? ' when '.$edge['consumer'] : '');
            $kinds[$key][] = (string) $edge['kind'];
        }

        // Two kinds of binding between one pair, for one consumer, stay
        // one entry that says both.
        $edges = array_map(function (array $kinds) {
            $kinds = array_values(array_unique($kinds));
            sort($kinds);

            return ['kind' => implode(', ', $kinds)];
        }, $kinds);

        return self::compact([
            'context' => $provider['context'] ?? null,
            'deferred' => $provider['deferred'] ?? false,
            'provides' => array_values($provider['provides'] ?? []),
            'side_effects' => self::sorted($provider['side_effects'] ?? []),
            'edges' => self::sorted($edges),
        ]);
    }

    /**
     * Something a provider binds that could not be named is `unresolved:0`,
     * `unresolved:1`, … in the order it was met, so removing the first would
     * read as the second changing. It is named by what it says instead, and
     * counted only among those that say the same.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<string, string>
     */
    protected static function unresolvedNames(array $nodes): array
    {
        $names = [];
        $seen = [];

        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');

            if (str_starts_with($id, 'unresolved:')) {
                $label = '? '.($node['label'] ?? '');
                $seen[$label] = ($seen[$label] ?? 0) + 1;
                $names[$id] = $seen[$label] === 1 ? $label : $label.' #'.$seen[$label];
            }
        }

        return $names;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    protected static function keyed(array $items, callable $facts): array
    {
        $keyed = [];

        foreach ($items as $item) {
            if (isset($item['id'])) {
                $keyed[(string) $item['id']] = $facts($item);
            }
        }

        return self::sorted($keyed);
    }

    /** @return array<string, mixed> */
    protected static function sorted(array $map): array
    {
        ksort($map, SORT_STRING);

        return $map;
    }

    /** Drops the maps that are empty; lists and scalars stay as they are. */
    protected static function compact(array $facts): array
    {
        return array_filter($facts, fn (mixed $value, string $key) => ! (is_array($value) && $value === [] && self::isMap($key)), ARRAY_FILTER_USE_BOTH);
    }

    /** The facts that hold a map keyed by name, not a list. */
    protected static function isMap(string $key): bool
    {
        return in_array($key, ['columns', 'relations', 'parameters', 'fields', 'request', 'response', 'payload', 'retry', 'traits', 'side_effects', 'edges'], true);
    }
}
