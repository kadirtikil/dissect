<?php

namespace KdrDev\Dissect\Architecture;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Every class, interface, trait and enum in the application, and every other
 * one of them it refers to.
 *
 * This is what "Sales depends on Billing" is made of: a domain depends on
 * another when one of its classes names one of the other's. Each file is
 * parsed once and its syntax tree dropped as soon as it is read, so the index
 * costs one parse per file and keeps only what it found. Nothing is loaded,
 * reflected or run.
 *
 * Built on demand and cached like every other payload: on a large app this
 * is the widest read dissect does.
 */
final class ClassIndex
{
    protected ?Parser $parser = null;

    /** @var array<int, array<string, mixed>>|null */
    protected ?array $declarations = null;

    public function __construct(
        protected SourceFiles $sources,
        protected ContextResolver $resolver,
    ) {}

    /**
     * The index as the page reads it.
     *
     * Only references between the application's own classes are listed. What
     * it uses from the framework and from packages is most of every file, and
     * is not what a boundary between two of its domains is about.
     *
     * @return array{
     *     classes: array<int, array{id: string, kind: string, abstract: bool, file: string, line: int, context: ?string, uses: array<int, array{to: string, kind: string, lines: array<int, int>}>}>,
     *     unreadable: array<int, string>,
     *     generated_at: string,
     * }
     */
    public function export(): array
    {
        $declarations = $this->declarations();
        $known = array_flip(array_filter(array_column($declarations, 'id'), 'is_string'));
        $classes = [];
        $unreadable = [];

        foreach ($declarations as $declaration) {
            if ($declaration['id'] === null) {
                $unreadable[] = $declaration['file'];

                continue;
            }

            $uses = [];

            foreach ($declaration['uses'] as $to => $kinds) {
                if (! isset($known[$to])) {
                    continue;
                }

                foreach ($kinds as $kind => $lines) {
                    sort($lines);
                    $uses[] = ['to' => $to, 'kind' => $kind, 'lines' => $lines];
                }
            }

            usort($uses, fn (array $a, array $b) => [$a['to'], $a['kind']] <=> [$b['to'], $b['kind']]);

            $classes[] = [
                'id' => $declaration['id'],
                'kind' => $declaration['kind'],
                'abstract' => $declaration['abstract'],
                'file' => $declaration['file'],
                'line' => $declaration['line'],
                'context' => $declaration['context'],
                'uses' => $uses,
            ];
        }

        usort($classes, fn (array $a, array $b) => strcmp($a['id'], $b['id']));

        return ['classes' => $classes, 'unreadable' => $unreadable, 'generated_at' => now()->toIso8601String()];
    }

    /**
     * Everything read, framework and package references included, for checks
     * that need to see them (a domain that must not reach for `Illuminate\Http`).
     *
     * A file that does not parse is one entry with a null id, so a caller can
     * say it was skipped rather than silently count fewer classes.
     *
     * @return array<int, array{id: ?string, kind: string, abstract: bool, file: string, line: int, context: ?string, uses: array<string, array<string, array<int, int>>>}>
     */
    public function declarations(): array
    {
        if ($this->declarations !== null) {
            return $this->declarations;
        }

        $declarations = [];

        foreach ($this->sources->files() as $path) {
            $found = $this->read($path);
            $file = $this->sources->relative($path);

            if ($found === null) {
                $declarations[] = ['id' => null, 'kind' => 'file', 'abstract' => false, 'file' => $file, 'line' => 0, 'context' => null, 'uses' => []];

                continue;
            }

            $context = $found === [] ? null : $this->resolver->forFile($path);

            foreach ($found as $declaration) {
                $declarations[] = ['file' => $file, 'context' => $context] + $declaration;
            }
        }

        return $this->declarations = $declarations;
    }

    /**
     * Cheap change signal: any file under the walked folders. Where a domain
     * comes from (domains.json) is covered by the payload cache's stamp.
     */
    public function fingerprint(): string
    {
        return substr(sha1('classes:'.$this->sources->signal()), 0, 16);
    }

    /**
     * The declarations in one file, or null if it cannot be parsed.
     *
     * @return array<int, array{id: string, kind: string, abstract: bool, line: int, uses: array<string, array<string, array<int, int>>>}>|null
     */
    protected function read(string $path): ?array
    {
        try {
            $this->parser ??= (new ParserFactory)->createForHostVersion();
            $statements = $this->parser->parse((string) file_get_contents($path));
        } catch (Throwable) {
            return null;
        }

        if ($statements === null) {
            return null;
        }

        $references = new References;
        (new NodeTraverser(new NameResolver, $references))->traverse($statements);

        return $references->declarations;
    }
}
