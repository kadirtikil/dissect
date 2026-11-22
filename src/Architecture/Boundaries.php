<?php

namespace KdrDev\Dissect\Architecture;

/**
 * Every place a domain's code breaks the boundary rule written for it.
 *
 * Read off the class index, so a violation is a place the code *names* a
 * class it should not: the same syntax-only reading, with the same Laravel
 * kinds (a relation, a dispatch, a container binding). Nothing is run.
 *
 * A domain with no rule is never checked, and an app with no rules gets no
 * violations: the rules are what turn checking on, one domain at a time.
 */
final class Boundaries
{
    public function __construct(
        protected ClassIndex $index,
        protected DomainFile $file,
        protected ContextResolver $resolver,
        protected SourceFiles $sources,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     rules: array<int, array{domain: string, may_use: ?array<int, string>, must_not_use: array<int, string>}>,
     *     violations: array<int, array{rule: string, domain: string, from: string, file: string, to: string, to_domain: ?string, namespace: ?string, kind: string, lines: array<int, int>, message: string}>,
     *     problems: array<int, string>,
     *     unchecked: array<int, string>,
     *     generated_at: string,
     * }
     */
    public function export(): array
    {
        $rules = $this->resolver->enabled() ? $this->file->rules() : [];
        $result = [
            'enabled' => $this->resolver->enabled(),
            'rules' => array_values(array_map(fn (BoundaryRule $rule) => $rule->toArray(), $rules)),
            'violations' => [],
            'problems' => [],
            'unchecked' => [],
            'generated_at' => now()->toIso8601String(),
        ];

        // Nothing to check, so nothing to read: the class index is the widest
        // read dissect does, and an app without rules never pays for it.
        if ($rules === []) {
            return $result;
        }

        $declarations = $this->index->declarations();
        $domains = $this->domains($declarations);

        $result['problems'] = $this->problems($rules, $domains);
        $result['violations'] = $this->violations($declarations, $rules);
        $result['unchecked'] = array_values(array_map(
            fn (array $declaration) => $declaration['file'],
            array_filter($declarations, fn (array $declaration) => $declaration['id'] === null),
        ));

        return $result;
    }

    /** Moves with any source file or with domains.json, where the rules are. */
    public function fingerprint(): string
    {
        return substr(sha1('boundaries:'.$this->sources->signal().':'.$this->file->signal()), 0, 16);
    }

    /**
     * @param  array<int, array<string, mixed>>  $declarations
     * @param  array<string, BoundaryRule>  $rules
     * @return array<int, array{rule: string, domain: string, from: string, file: string, to: string, to_domain: ?string, namespace: ?string, kind: string, lines: array<int, int>, message: string}>
     */
    protected function violations(array $declarations, array $rules): array
    {
        $contexts = [];

        foreach ($declarations as $declaration) {
            if (is_string($declaration['id'])) {
                $contexts[$declaration['id']] = $declaration['context'];
            }
        }

        $violations = [];

        foreach ($declarations as $declaration) {
            $domain = $declaration['context'];
            $rule = $domain !== null ? ($rules[$domain] ?? null) : null;

            if ($rule === null || $declaration['id'] === null) {
                continue;
            }

            foreach ($declaration['uses'] as $to => $kinds) {
                // A class in no domain says nothing about where a boundary
                // is, the same rule the domain links on the page follow.
                $toDomain = $contexts[$to] ?? null;
                $namespace = $rule->forbids($to);
                $crosses = $toDomain !== null && ! $rule->allows($toDomain);

                if (! $crosses && $namespace === null) {
                    continue;
                }

                foreach ($kinds as $kind => $lines) {
                    sort($lines);
                    $at = $this->describe($declaration['id'], $to, $kind, $lines);

                    if ($crosses) {
                        $violations[] = $this->violation('may_use', $declaration, $to, $toDomain, null, $kind, $lines,
                            "{$domain} may not use {$toDomain}: {$at}.");
                    }

                    if ($namespace !== null) {
                        $violations[] = $this->violation('must_not_use', $declaration, $to, $toDomain, $namespace, $kind, $lines,
                            "{$domain} must not use {$namespace}: {$at}.");
                    }
                }
            }
        }

        usort($violations, fn (array $a, array $b) => [$a['domain'], $a['file'], $a['lines'][0] ?? 0, $a['to'], $a['rule']]
            <=> [$b['domain'], $b['file'], $b['lines'][0] ?? 0, $b['to'], $b['rule']]);

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $declaration
     * @param  array<int, int>  $lines
     * @return array{rule: string, domain: string, from: string, file: string, to: string, to_domain: ?string, namespace: ?string, kind: string, lines: array<int, int>, message: string}
     */
    protected function violation(string $rule, array $declaration, string $to, ?string $toDomain, ?string $namespace, string $kind, array $lines, string $message): array
    {
        return [
            'rule' => $rule,
            'domain' => $declaration['context'],
            'from' => $declaration['id'],
            'file' => $declaration['file'],
            'to' => $to,
            'to_domain' => $toDomain,
            'namespace' => $namespace,
            'kind' => $kind,
            'lines' => $lines,
            'message' => $message,
        ];
    }

    /**
     * A rule that names a domain nothing defines is almost always a typo, and
     * a typo in an allow-list is a check that never fires.
     *
     * @param  array<string, BoundaryRule>  $rules
     * @param  array<string, true>  $domains
     * @return array<int, string>
     */
    protected function problems(array $rules, array $domains): array
    {
        $problems = [];

        foreach ($rules as $name => $rule) {
            if (! isset($domains[$name])) {
                $problems[] = "rules.{$name}: no domain is called {$name}.";
            }

            foreach ($rule->mayUse ?? [] as $other) {
                if (! isset($domains[$other])) {
                    $problems[] = "rules.{$name}.may_use: no domain is called {$other}.";
                }
            }
        }

        return $problems;
    }

    /**
     * Every domain there is: what folders and domains.json name, and what an
     * attribute puts a class in.
     *
     * @param  array<int, array<string, mixed>>  $declarations
     * @return array<string, true>
     */
    protected function domains(array $declarations): array
    {
        $domains = array_fill_keys(array_keys($this->resolver->contexts()), true);

        foreach ($declarations as $declaration) {
            if (is_string($declaration['context'])) {
                $domains[$declaration['context']] = true;
            }
        }

        return $domains;
    }

    /** @param  array<int, int>  $lines */
    protected function describe(string $from, string $to, string $kind, array $lines): string
    {
        return $this->short($from).' → '.$this->short($to).' ('.$kind.', '.(count($lines) === 1 ? 'line ' : 'lines ').implode(', ', $lines).')';
    }

    protected function short(string $class): string
    {
        $cut = strrpos($class, '\\');

        return $cut === false ? $class : substr($class, $cut + 1);
    }
}
