<?php

namespace KdrDev\Dissect\Digest;

use KdrDev\Dissect\Changes\Report;
use KdrDev\Dissect\Support\ClassNames;

/**
 * Every payload as Markdown that is short enough to read, and to hand an agent.
 *
 * One line per thing, with the details it is usually looked up for indented
 * under it. Nothing is added that the payload does not say, and nothing it
 * marks as a guess is shown as a fact. Long lists are cut with a count of what
 * was left out, never silently.
 *
 * Each item has its own method, so a caller that wants one model or one route
 * gets exactly what the full list would have said about it.
 */
final class Markdown
{
    /** How many request or response fields a route lists before counting the rest. */
    public const FIELDS = 25;

    /** Edge kinds that tie a contract to what it resolves to. */
    protected const BINDINGS = ['bind', 'singleton', 'scoped', 'instance', 'contextual'];

    /** Words for how a domain was found, as the page says them. */
    protected const SOURCES = [
        'config' => 'domains.json',
        'attribute' => '#[Domain] attribute',
        'modules' => 'Modules/ folder',
        'app-modules' => 'app-modules/ folder',
        'domain-folder' => 'Domain/ folder',
        'hexagonal' => 'hexagonal layers',
        'layer-folders' => 'layer sub-folders',
        'packages' => 'packages/ folder',
    ];

    /** @param  array<string, mixed>  $payload */
    public function render(string $surface, array $payload): string
    {
        return match ($surface) {
            'schema' => $this->schema($payload),
            'routes' => $this->routes($payload),
            'jobs' => $this->jobs($payload),
            'providers' => $this->providers($payload),
            'domains' => $this->domains($payload),
            'classes' => $this->classes($payload),
            'boundaries' => $this->boundaries($payload),
        };
    }

    // ── Models ────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $schema */
    public function schema(array $schema): string
    {
        $nodes = $schema['nodes'] ?? [];
        $edges = $schema['edges'] ?? [];
        $names = $this->modelNames($schema);

        $lines = ['# Models', '', count($nodes).' models, '.count($edges).' relations.', ''];

        foreach ($nodes as $node) {
            $lines[] = $this->model($node, $schema, $names);
        }

        return $this->join($lines);
    }

    /**
     * One model: its table, domain, columns and the relations it declares.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $schema
     * @param  array<string, string>|null  $names
     */
    public function model(array $node, array $schema, ?array $names = null): string
    {
        $names ??= $this->modelNames($schema);
        $contexts = $this->contexts($schema);

        $head = '## '.($names[$node['id']] ?? ClassNames::base($node['id'])).' `'.$node['table'].'`';
        $lines = [$head, $this->meta($node['class'], $node['context'] ?? null)];

        $columns = array_map($this->column(...), $node['columns'] ?? []);
        $lines[] = '- columns: '.($columns === [] ? 'none read (no table, or no connection)' : implode('; ', $columns));

        foreach ($schema['edges'] ?? [] as $edge) {
            if ($edge['source'] === $node['id']) {
                $lines[] = '- '.$this->relation($edge, $names, $contexts);
            }
        }

        $incoming = [];

        foreach ($schema['edges'] ?? [] as $edge) {
            if ($edge['target'] === $node['id'] && $edge['source'] !== $node['id']) {
                $incoming[] = ($names[$edge['source']] ?? ClassNames::base($edge['source'])).'.'.$edge['name'];
            }
        }

        if ($incoming !== []) {
            $lines[] = '- used by: '.implode(', ', $incoming);
        }

        return $this->join($lines);
    }

    /** @param  array<string, mixed>|string  $column */
    protected function column(array|string $column): string
    {
        if (is_string($column)) {
            return $column;
        }

        $flags = array_keys(array_filter([
            'pk' => $column['increments'] ?? false,
            'nullable' => $column['nullable'] ?? false,
            'unique' => ($column['unique'] ?? false) && ! ($column['increments'] ?? false),
            'virtual' => $column['virtual'] ?? false,
            'hidden' => $column['hidden'] ?? false,
        ]));

        $cast = $column['cast'] ?? null;
        $type = $column['label'] ?? $column['type'] ?? $column['kind'] ?? '';

        return trim($column['name'].' '.$type.($flags === [] ? '' : ' '.implode(' ', $flags)).($cast ? ' cast:'.$cast : ''));
    }

    /**
     * @param  array<string, mixed>  $edge
     * @param  array<string, string>  $names
     * @param  array<string, ?string>  $contexts  Every scanned model, keyed to its domain.
     */
    protected function relation(array $edge, array $names, array $contexts): string
    {
        $keys = ($edge['keys'] ?? []) + ['source' => null, 'target' => null, 'via' => null];
        $detail = [$edge['type']];

        // The owner of a morph-to is a different model on every row, so the
        // payload points it back at its own model. Say that, not a self-loop.
        if ($edge['type'] === 'MorphTo') {
            if ($keys['source'] !== null) {
                $detail[] = 'by '.$keys['source'];
            }

            return $edge['name'].' → any model ('.implode(', ', $detail).')';
        }

        if ($keys['source'] !== null && $keys['target'] !== null) {
            $detail[] = $keys['source'].' → '.$keys['target'];
        }

        if ($keys['via'] !== null) {
            $detail[] = 'via '.$keys['via'];
        }

        $line = $edge['name'].' → '.($names[$edge['target']] ?? ClassNames::base($edge['target'])).' ('.implode(', ', $detail).')';

        if (! array_key_exists($edge['target'], $contexts)) {
            return $line.' (not in scan)';
        }

        $from = $contexts[$edge['source']] ?? null;
        $to = $contexts[$edge['target']];

        return $from !== null && $to !== null && $from !== $to ? $line.' — crosses into '.$to : $line;
    }

    /**
     * Every scanned model, keyed to its domain or null.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, ?string>
     */
    protected function contexts(array $schema): array
    {
        $contexts = [];

        foreach ($schema['nodes'] ?? [] as $node) {
            $contexts[$node['id']] = $node['context'] ?? null;
        }

        return $contexts;
    }

    /**
     * Short names for every model, and every model a relation reaches.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, string>
     */
    protected function modelNames(array $schema): array
    {
        $ids = array_column($schema['nodes'] ?? [], 'id');

        foreach ($schema['edges'] ?? [] as $edge) {
            $ids[] = $edge['source'];
            $ids[] = $edge['target'];
        }

        return ClassNames::short($ids);
    }

    // ── Routes ────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $payload */
    public function routes(array $payload): string
    {
        $routes = $payload['routes'] ?? [];
        $lines = ['# Routes', '', count($routes).' routes.', ''];

        foreach ($routes as $route) {
            $lines[] = $this->route($route);
        }

        return $this->join($lines);
    }

    /**
     * One route: how it is reached, what it runs, what it takes and returns.
     *
     * @param  array<string, mixed>  $route
     */
    public function route(array $route, int $fields = self::FIELDS): string
    {
        $address = implode('|', $route['methods']).' '.($route['domain'] ? $route['domain'].'/' : '').$route['uri'];
        $head = '- `'.$address.'`'.($route['name'] ? ' '.$route['name'] : '').' → '.$route['action']['label'];

        $tags = array_filter([
            $route['stack'] ?? null,
            ($route['group'] ?? 'app') === 'app' ? null : $route['group'],
            isset($route['context']) && $route['context'] !== null ? 'domain '.$route['context'] : null,
        ]);

        $lines = [$head.($tags === [] ? '' : ' · '.implode(' · ', $tags))];

        if ($route['action']['class'] ?? null) {
            $lines[] = '  - class: '.$route['action']['class'];
        }

        if ($route['middleware'] ?? []) {
            $lines[] = '  - middleware: '.implode(', ', $route['middleware']);
        }

        $request = $route['request'] ?? null;

        if ($request && $request['source'] !== 'none') {
            $lines[] = '  - request ('.$this->source($request).'): '.$this->fields(
                array_map(fn (array $f) => $f['path'].($f['required'] ? '*' : '').' '.$f['type'], $request['fields'] ?? []),
                $fields,
            );
        }

        $response = $route['response'] ?? null;

        if ($response && $response['source'] !== 'unknown') {
            $status = $response['status'] ? $response['status'].' ' : '';
            $lines[] = '  - response '.$status.'('.$this->source($response).'): '.$this->fields(
                array_map(fn (array $f) => $f['path'].($f['conditional'] ? '?' : ''), $response['fields'] ?? []),
                $fields,
            );
        }

        return $this->join($lines);
    }

    /** @param  array<string, mixed>  $shape */
    protected function source(array $shape): string
    {
        $parts = [$shape['source']];

        if ($shape['class'] ?? null) {
            $parts[] = ClassNames::base($shape['class']);
        }

        if (($shape['confidence'] ?? 'certain') !== 'certain') {
            $parts[] = $shape['confidence'];
        }

        return implode(' ', $parts);
    }

    /** @param  array<int, string>  $fields */
    protected function fields(array $fields, int $limit): string
    {
        if ($fields === []) {
            return 'no fields read';
        }

        $shown = array_slice($fields, 0, $limit);
        $rest = count($fields) - count($shown);

        return implode('; ', $shown).($rest > 0 ? "; +{$rest} more" : '');
    }

    // ── Jobs ──────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $payload */
    public function jobs(array $payload): string
    {
        $jobs = $payload['jobs'] ?? [];
        $lines = ['# Jobs', '', count($jobs).' queued classes.', ''];

        foreach ($jobs as $job) {
            $lines[] = $this->job($job);
        }

        return $this->join($lines);
    }

    /**
     * One job: where it runs, how it retries, what it carries, who sends it.
     *
     * @param  array<string, mixed>  $job
     */
    public function job(array $job): string
    {
        $lines = ['- **'.$job['name'].'** ('.$job['kind'].') '.$this->meta($job['class'], $job['context'] ?? null)];

        $queue = $job['queue'] ?? ($job['queue_source'] === 'mixed' ? 'mixed' : 'default');
        $retry = $job['retry'] ?? [];
        $run = ['queue '.$queue];

        foreach (['tries', 'timeout', 'maxExceptions'] as $key) {
            if (($retry[$key] ?? null) !== null) {
                $run[] = $key.' '.$retry[$key];
            }
        }

        if (($retry['backoff'] ?? null) !== null) {
            $run[] = 'backoff '.implode(',', (array) $retry['backoff']);
        }

        if ($retry['retryUntil'] ?? false) {
            $run[] = 'retryUntil';
        }

        $traits = array_keys(array_filter($job['traits'] ?? []));
        $lines[] = '  - '.implode(' · ', [...$run, ...$traits]);

        if ($job['models'] ?? []) {
            $lines[] = '  - carries: '.implode(', ', array_map(ClassNames::base(...), $job['models']));
        }

        if ($job['middleware'] ?? []) {
            $lines[] = '  - middleware: '.implode(', ', $job['middleware']);
        }

        if (($job['dispatched_by'] ?? []) === []) {
            $lines[] = '  - dispatched by: nothing found (dead, or dispatched dynamically)';
        }

        foreach ($job['dispatched_by'] ?? [] as $site) {
            $route = $site['route'] ?? null;
            $lines[] = '  - dispatched by: '.$site['label'].($route ? ' ('.str_replace(':', ' ', $route).')' : '').' at '.$site['file'].':'.$site['line'];
        }

        return $this->join($lines);
    }

    // ── Providers ─────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $payload */
    public function providers(array $payload): string
    {
        $providers = $payload['providers'] ?? [];
        $lines = ['# Service providers', '', count($providers).' providers.', ''];

        foreach ($providers as $provider) {
            $lines[] = $this->provider($provider);
        }

        return $this->join($lines);
    }

    /**
     * One provider: what it binds, and what those bindings pull in.
     *
     * @param  array<string, mixed>  $provider
     */
    public function provider(array $provider): string
    {
        $flags = array_keys(array_filter([
            'deferred' => $provider['deferred'] ?? false,
            'partly read' => $provider['partial'] ?? false,
            'cut short' => $provider['truncated'] ?? false,
        ]));

        $lines = ['- **'.$provider['name'].'** '.$this->meta($provider['class'], $provider['context'] ?? null).($flags === [] ? '' : ' · '.implode(' · ', $flags))];
        $nodes = array_column($provider['nodes'] ?? [], null, 'id');
        $edges = $provider['edges'] ?? [];
        $label = fn (string $id) => $nodes[$id]['label'] ?? $id;
        $paired = [];

        foreach ($edges as $i => $edge) {
            if (isset($paired[$i])) {
                continue;
            }

            $from = $nodes[$edge['source']]['kind'] ?? null;
            $line = $from === 'provider' && $edge['source'] !== $provider['id'] ? $label($edge['source']).': ' : '';

            // A binding is drawn as two edges, provider to contract and
            // contract to concrete. Read together they are one sentence.
            $next = $from === 'provider' && in_array($edge['kind'], self::BINDINGS, true)
                ? $this->bound($edges, $edge, $paired)
                : null;

            if ($next !== null) {
                $paired[$next] = true;
                $line .= $edge['kind'].' '.$label($edge['target']).' → '.$label($edges[$next]['target']);
                $edge['confidence'] = $this->weaker($edge['confidence'] ?? 'certain', $edges[$next]['confidence'] ?? 'certain');
            } elseif ($from === 'provider') {
                $line .= $edge['kind'].' '.$label($edge['target']);
            } else {
                $line .= $label($edge['source']).' '.$edge['kind'].' '.$label($edge['target']);
            }

            if ($edge['consumer'] ?? null) {
                $line .= ' (when '.ClassNames::base($edge['consumer']).' asks)';
            }

            if (($edge['confidence'] ?? 'certain') !== 'certain') {
                $line .= ' ('.$edge['confidence'].')';
            }

            $lines[] = '  - '.$line;
        }

        if ($provider['side_effects'] ?? []) {
            $effects = [];

            foreach ($provider['side_effects'] as $kind => $count) {
                $effects[] = $kind.' '.$count;
            }

            $lines[] = '  - also registers: '.implode(', ', $effects);
        }

        if ($provider['provides'] ?? []) {
            $lines[] = '  - provides: '.implode(', ', array_map(ClassNames::base(...), $provider['provides']));
        }

        return $this->join($lines);
    }

    /**
     * The contract-to-concrete half of a provider's binding, if it has one.
     *
     * @param  array<int, array<string, mixed>>  $edges
     * @param  array<string, mixed>  $edge
     * @param  array<int, true>  $paired
     */
    protected function bound(array $edges, array $edge, array $paired): ?int
    {
        foreach ($edges as $i => $candidate) {
            if (! isset($paired[$i])
                && $candidate['source'] === $edge['target']
                && $candidate['kind'] === $edge['kind']
                && ($candidate['consumer'] ?? null) === ($edge['consumer'] ?? null)) {
                return $i;
            }
        }

        return null;
    }

    protected function weaker(string $a, string $b): string
    {
        $order = ['certain' => 0, 'inferred' => 1, 'unknown' => 2];

        return ($order[$a] ?? 2) >= ($order[$b] ?? 2) ? $a : $b;
    }

    // ── Domains ───────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $payload */
    public function domains(array $payload): string
    {
        if (! ($payload['enabled'] ?? true)) {
            return $this->join(['# Domains', '', 'Domain detection is switched off (dissect.domains.enabled).']);
        }

        $domains = $payload['domains'] ?? [];
        $lines = ['# Domains', ''];

        if ($domains === []) {
            $lines[] = 'No domains found: no folder layout, domains.json entry or #[Domain] attribute names one.';
        }

        foreach ($domains as $domain) {
            $lines[] = $this->domain($domain, false);
        }

        if ($payload['findings'] ?? []) {
            $lines[] = '';
            $lines[] = '## To check';

            foreach ($payload['findings'] as $finding) {
                $lines[] = '- '.ClassNames::base($finding['class']).': '.$finding['message'].' ('.$finding['file'].')';
            }
        }

        if ($payload['problems'] ?? []) {
            $lines[] = '';
            $lines[] = '## Problems with domains.json';

            foreach ($payload['problems'] as $problem) {
                $lines[] = '- '.$problem;
            }
        }

        if ($domains !== [] && ($payload['unassigned'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = count($payload['unassigned']).' classes are in no domain.';
        }

        return $this->join($lines);
    }

    /**
     * One domain: how it was found, where it lives, and optionally its classes.
     *
     * @param  array<string, mixed>  $domain
     */
    public function domain(array $domain, bool $classes = true): string
    {
        $count = count($domain['classes'] ?? []);
        $sources = array_map(fn (string $s) => self::SOURCES[$s] ?? $s, $domain['sources'] ?? []);

        $lines = ['- **'.$domain['name'].'** ('.implode(', ', $sources).') · '.$count.' '.($count === 1 ? 'class' : 'classes')];

        if ($domain['roots'] ?? []) {
            $lines[] = '  - in: '.implode(', ', $domain['roots']);
        }

        if ($classes) {
            foreach ($domain['classes'] ?? [] as $class) {
                $lines[] = '  - '.$class;
            }
        }

        return $this->join($lines);
    }

    // ── Classes ───────────────────────────────────────────────────────────

    /**
     * Every class and the app classes it uses, one line each, opened by how
     * the domains depend on one another when the app has any.
     *
     * @param  array<string, mixed>  $payload
     */
    public function classes(array $payload): string
    {
        $classes = $payload['classes'] ?? [];
        $names = ClassNames::short(array_column($classes, 'id'));
        $contexts = array_column($classes, 'context', 'id');
        $references = array_sum(array_map(fn (array $class) => count($class['uses'] ?? []), $classes));

        $lines = ['# Classes', '', count($classes).' classes, '.$references.' references between them.'];

        if ($payload['unreadable'] ?? []) {
            $lines[] = count($payload['unreadable']).' '.(count($payload['unreadable']) === 1 ? 'file' : 'files').' could not be parsed: '.implode(', ', $payload['unreadable']).'.';
        }

        $between = $this->between($classes, $contexts);

        if ($between !== []) {
            $lines[] = '';
            $lines[] = '## Between domains';
            $lines = [...$lines, ...$between];
            $lines[] = '';
            $lines[] = '## Every class';
        }

        $lines[] = '';

        foreach ($classes as $class) {
            $targets = [];

            foreach ($class['uses'] ?? [] as $use) {
                $to = $contexts[$use['to']] ?? null;
                $targets[$use['to']] = $names[$use['to']].($to !== null && $to !== ($class['context'] ?? null) ? ' ['.$to.']' : '');
            }

            $lines[] = '- **'.$names[$class['id']].'** '.$class['kind']
                .(($class['context'] ?? null) !== null ? ' · domain '.$class['context'] : '')
                .($targets === [] ? '' : ' · uses '.implode(', ', $targets));
        }

        return $this->join($lines);
    }

    /**
     * One class: where it is, what it uses, and what uses it.
     *
     * @param  array<string, mixed>  $class
     * @param  array<string, mixed>  $payload  The whole index, for who uses this class.
     */
    public function class(array $class, array $payload): string
    {
        $all = $payload['classes'] ?? [];
        $names = ClassNames::short(array_column($all, 'id'));
        $contexts = array_column($all, 'context', 'id');
        $context = $class['context'] ?? null;

        $lines = [
            '## '.$names[$class['id']].' ('.$class['kind'].($class['abstract'] && $class['kind'] === 'class' ? ', abstract' : '').')',
            $this->meta($class['id'], $context).' · '.$class['file'].':'.$class['line'],
        ];

        $uses = $this->grouped($class['uses'] ?? []);

        if ($uses === []) {
            $lines[] = '- uses: no other class in the app';
        } else {
            $lines[] = '- uses:';

            foreach ($uses as $to => $kinds) {
                $other = $contexts[$to] ?? null;
                $lines[] = '  - '.$names[$to].': '.$kinds.($other !== null && $context !== null && $other !== $context ? ' — crosses into '.$other : '');
            }
        }

        $usedBy = [];

        foreach ($all as $other) {
            $toThis = array_values(array_filter($other['uses'] ?? [], fn (array $use) => $use['to'] === $class['id']));

            if ($toThis !== []) {
                $from = $other['context'] ?? null;
                $usedBy[] = '  - '.$names[$other['id']].': '.$this->grouped($toThis)[$class['id']]
                    .($from !== null && $context !== null && $from !== $context ? ' — from '.$from : '');
            }
        }

        $lines[] = $usedBy === [] ? '- used by: no other class in the app' : '- used by:';

        return $this->join([...$lines, ...$usedBy]);
    }

    /**
     * References per target, as `relation line 6; type lines 9, 12`.
     *
     * @param  array<int, array{to: string, kind: string, lines: array<int, int>}>  $uses
     * @return array<string, string>
     */
    protected function grouped(array $uses): array
    {
        $grouped = [];

        foreach ($uses as $use) {
            $lines = $use['lines'] ?? [];
            $grouped[$use['to']][] = $use['kind'].' '.(count($lines) === 1 ? 'line ' : 'lines ').implode(', ', $lines);
        }

        return array_map(fn (array $kinds) => implode('; ', $kinds), $grouped);
    }

    /**
     * How often each domain's classes name another's, by kind.
     *
     * @param  array<int, array<string, mixed>>  $classes
     * @param  array<string, ?string>  $contexts
     * @return array<int, string>
     */
    protected function between(array $classes, array $contexts): array
    {
        $counts = [];

        foreach ($classes as $class) {
            $from = $class['context'] ?? null;

            foreach ($class['uses'] ?? [] as $use) {
                $to = $contexts[$use['to']] ?? null;

                if ($from !== null && $to !== null && $from !== $to) {
                    $counts[$from.' → '.$to][$use['kind']] = ($counts[$from.' → '.$to][$use['kind']] ?? 0) + 1;
                }
            }
        }

        ksort($counts);

        return array_map(function (string $pair) use ($counts) {
            arsort($counts[$pair]);
            $kinds = array_map(fn (string $kind, int $count) => $kind.' '.$count, array_keys($counts[$pair]), $counts[$pair]);

            return '- '.$pair.': '.array_sum($counts[$pair]).' ('.implode(', ', $kinds).')';
        }, array_keys($counts));
    }

    // ── Boundaries ────────────────────────────────────────────────────────

    /**
     * The rules, what is wrong with them, and every violation with the file
     * and line it is at: what an agent needs to fix one.
     *
     * @param  array<string, mixed>  $payload
     */
    public function boundaries(array $payload): string
    {
        $rules = $payload['rules'] ?? [];
        $violations = $payload['violations'] ?? [];
        $lines = ['# Boundaries', ''];

        if (! ($payload['enabled'] ?? true)) {
            return $this->join([...$lines, 'Domain detection is switched off (dissect.domains.enabled), so no rule is checked.']);
        }

        if ($rules === []) {
            return $this->join([...$lines, 'No boundary rules. Add a "rules" object to .dissect/domains.json to check a domain.']);
        }

        $lines[] = count($rules).' '.(count($rules) === 1 ? 'rule' : 'rules').', '
            .($violations === [] ? 'no violations.' : count($violations).' '.(count($violations) === 1 ? 'violation.' : 'violations.'));

        if ($payload['unchecked'] ?? []) {
            $lines[] = count($payload['unchecked']).' '.(count($payload['unchecked']) === 1 ? 'file' : 'files').' could not be parsed, so '
                .(count($payload['unchecked']) === 1 ? 'it was' : 'they were').' not checked: '.implode(', ', $payload['unchecked']).'.';
        }

        $lines[] = '';
        $lines[] = '## Rules';

        foreach ($rules as $rule) {
            $lines[] = '- '.$this->rule($rule);
        }

        if ($payload['problems'] ?? []) {
            $lines[] = '';
            $lines[] = '## Problems with the rules';

            foreach ($payload['problems'] as $problem) {
                $lines[] = '- '.$problem;
            }
        }

        $byDomain = [];

        foreach ($violations as $violation) {
            $byDomain[$violation['domain']][] = $violation;
        }

        foreach ($byDomain as $domain => $found) {
            $lines[] = '';
            $lines[] = '## '.$domain.' ('.count($found).')';

            foreach ($found as $violation) {
                $lines[] = '- '.$violation['message'].' `'.$violation['file'].':'.($violation['lines'][0] ?? 0).'`';
            }
        }

        return $this->join($lines);
    }

    /**
     * A dissect:check result, written for the agent whose edit it is about:
     * what broke, where, the rule it broke, and what not to do about it.
     *
     * @param  array<string, mixed>  $result  `dissect:check --format=json`
     */
    public function check(array $result): string
    {
        if ($result['passed'] ?? false) {
            return $this->join(['dissect: no boundary rule is broken'.(($result['rules'] ?? []) === [] ? ' (there are no rules).' : '.')]);
        }

        $lines = [];
        $violations = $result['violations'] ?? [];

        if ($result['problems'] ?? []) {
            $lines[] = 'dissect: .dissect/domains.json has a problem, so its boundary rules are not all checked:';

            foreach ($result['problems'] as $problem) {
                $lines[] = '- '.$problem;
            }
        }

        if ($violations !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }

            $lines[] = 'dissect: '.(count($violations) === 1 ? 'this breaks a boundary rule' : 'this breaks boundary rules').' in .dissect/domains.json:';

            foreach ($violations as $violation) {
                $lines[] = '- `'.$violation['file'].':'.($violation['lines'][0] ?? 0).'` '.$violation['message'];
            }

            $rules = array_column($result['rules'] ?? [], null, 'domain');
            $broken = array_values(array_unique(array_column($violations, 'domain')));

            $lines[] = '';
            $lines[] = 'The '.(count($broken) === 1 ? 'rule' : 'rules').':';

            foreach ($broken as $domain) {
                $lines[] = '- '.(isset($rules[$domain]) ? $this->rule($rules[$domain]) : '**'.$domain.'**');
            }

            $lines[] = '';
            $lines[] = 'Change the code so it keeps to the rule. The rule says where this team wants the boundary;'
                .' only change .dissect/domains.json if you were asked to move it.';
        }

        return $this->join($lines);
    }

    /** @param  array<string, mixed>  $rule */
    /**
     * What changed since a snapshot, part by part, worst first: for a pull
     * request comment, or for an agent to read back what it did.
     *
     * @param  array{summary: array<string, int>, changes: array<int, array<string, mixed>>}  $report
     */
    public function changes(array $report, string $against): string
    {
        if ($report['changes'] === []) {
            return $this->join(["# Changes since {$against}", '', 'Nothing changed.']);
        }

        $lines = ["# Changes since {$against}", '', Report::summary($report['summary']).'.'];
        $headings = ['models' => 'Models', 'routes' => 'Routes', 'jobs' => 'Jobs', 'providers' => 'Service providers'];
        $part = null;

        foreach ($report['changes'] as $change) {
            if ($change['part'] !== $part) {
                $part = $change['part'];
                array_push($lines, '', '## '.($headings[$part] ?? $part), '');
            }

            $lines[] = '- '.$this->severity($change['severity']).' `'.$change['text'].'`'.($change['name'] !== $change['id'] && $part !== 'routes' ? ' · `'.$change['id'].'`' : '');

            foreach ($change['facts'] as $fact) {
                $lines[] = '  - '.$this->severity($fact['severity']).' `'.$fact['text'].'`';
            }
        }

        return $this->join($lines);
    }

    /** Breaking stands out; the rest is a word. */
    protected function severity(string $severity): string
    {
        return $severity === 'breaking' ? '**breaking**' : $severity;
    }

    protected function rule(array $rule): string
    {
        $parts = [];

        if (($rule['may_use'] ?? null) !== null) {
            $parts[] = $rule['may_use'] === [] ? 'may use no other domain' : 'may use '.implode(', ', $rule['may_use']);
        }

        if ($rule['must_not_use'] ?? []) {
            $parts[] = 'must not use '.implode(', ', $rule['must_not_use']);
        }

        return '**'.$rule['domain'].'** '.($parts === [] ? 'has an empty rule' : implode('; ', $parts));
    }

    // ── Shared ────────────────────────────────────────────────────────────

    protected function meta(string $class, ?string $context): string
    {
        return '`'.$class.'`'.($context !== null ? ' · domain '.$context : '');
    }

    /** @param  array<int, string>  $lines */
    protected function join(array $lines): string
    {
        return rtrim(implode("\n", $lines))."\n";
    }
}
