<?php

namespace KdrDev\Dissect\ProviderTree;

use Illuminate\Support\ServiceProvider;
use KdrDev\Dissect\Jobs\ProjectPath;
use ReflectionClass;
use Throwable;

/**
 * One provider's tree while it is being read.
 *
 * Nodes are keyed by id so the same name reached twice is one node — the
 * earliest depth wins, because that is the tier the layout should draw it on,
 * and the firmest confidence wins, because one certain sighting outweighs any
 * number of guesses. Edges are keyed by id for the same reason.
 *
 * The inspector and the dependency resolver both add to it, which is why it is
 * its own object rather than arrays threaded through both.
 */
final class TreeBuilder
{
    /** Firmest first — the index is the ordering, as in {@see \KdrDev\Dissect\Jobs\Confidence}. */
    protected const LADDER = ['certain', 'inferred', 'unknown'];

    /** @var array<string, ProviderNode> */
    protected array $nodes = [];

    /** @var array<string, ProviderEdge> */
    protected array $edges = [];

    /** @var array<string, int> */
    protected array $sideEffects = [];

    protected int $unresolved = 0;

    /** @param  class-string  $provider */
    public function __construct(public readonly string $provider)
    {
        $this->named($provider, NodeKind::Provider, 0, 'certain');
    }

    /**
     * A node for a class or container key, returning its id.
     *
     * Everything about the node other than its position — label, origin, file,
     * summary — is read off the name itself, so every caller describes a class
     * the same way.
     */
    public function named(string $name, NodeKind $kind, int $depth, string $confidence): string
    {
        $name = ltrim($name, '\\');
        $reflection = $this->reflect($name);
        $origin = $this->origin($reflection);

        return $this->add(new ProviderNode(
            id: $name,
            kind: $kind,
            label: $reflection === null ? $name : $reflection->getShortName(),
            origin: $origin,
            depth: $depth,
            confidence: $confidence,
            class: $reflection?->getName(),
            file: $origin === Origin::App && $reflection->getFileName() !== false
                ? ProjectPath::relative($reflection->getFileName())
                : null,
            summary: $reflection === null ? null : $this->summary($reflection),
        ));
    }

    /**
     * What a name is, when nothing about where it was found says otherwise.
     *
     * A provider, a class that can be constructed, or — for an interface, an
     * abstract class or a plain key — a contract.
     */
    public function kindOf(string $name): NodeKind
    {
        $reflection = $this->reflect(ltrim($name, '\\'));

        return match (true) {
            $reflection === null || ! $reflection->isInstantiable() => NodeKind::Contract,
            $reflection->isSubclassOf(ServiceProvider::class) => NodeKind::Provider,
            default => NodeKind::Concrete,
        };
    }

    /**
     * Something known to be there that could not be named, returning its id.
     *
     * Each one is its own node: two unreadable bindings are two gaps in the
     * tree, not one.
     */
    public function unresolved(string $label, int $depth): string
    {
        return $this->add(new ProviderNode(
            id: 'unresolved:'.$this->unresolved++,
            kind: NodeKind::Unresolved,
            label: $label,
            origin: Origin::None,
            depth: $depth,
            confidence: 'unknown',
        ));
    }

    public function edge(string $source, string $target, EdgeKind $kind, string $confidence, ?string $consumer = null): void
    {
        $edge = new ProviderEdge($source, $target, $kind, $confidence, $consumer);

        $existing = $this->edges[$edge->id()] ?? null;

        if ($existing === null || $this->firmer($confidence, $existing->confidence)) {
            $this->edges[$edge->id()] = $edge;
        }
    }

    public function sideEffect(SideEffect $effect): void
    {
        $this->sideEffects[$effect->value] = ($this->sideEffects[$effect->value] ?? 0) + 1;
    }

    /** @param  array<int, string>  $provides */
    public function build(bool $deferred, array $provides): ProviderDescription
    {
        return new ProviderDescription(
            provider: $this->provider,
            nodes: array_values($this->nodes),
            edges: array_values($this->edges),
            sideEffects: $this->sideEffects,
            deferred: $deferred,
            provides: $provides,
        );
    }

    protected function add(ProviderNode $node): string
    {
        $existing = $this->nodes[$node->id] ?? null;

        if ($existing === null) {
            $this->nodes[$node->id] = $node;

            return $node->id;
        }

        // Kept in its original position in the list, so the root stays first.
        $this->nodes[$node->id] = new ProviderNode(
            id: $existing->id,
            kind: $existing->kind,
            label: $existing->label,
            origin: $existing->origin,
            depth: min($existing->depth, $node->depth),
            confidence: $this->firmer($node->confidence, $existing->confidence) ? $node->confidence : $existing->confidence,
            class: $existing->class,
            file: $existing->file,
            summary: $existing->summary,
        );

        return $node->id;
    }

    protected function firmer(string $candidate, string $than): bool
    {
        return array_search($candidate, self::LADDER, true) < array_search($than, self::LADDER, true);
    }

    /** @return ReflectionClass<object>|null */
    protected function reflect(string $name): ?ReflectionClass
    {
        try {
            // A container key like `workbench.transcoder` goes through the
            // autoloader once and comes back false, which is the answer.
            if (! class_exists($name) && ! interface_exists($name) && ! enum_exists($name)) {
                return null;
            }

            return new ReflectionClass($name);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  ReflectionClass<object>|null  $reflection */
    protected function origin(?ReflectionClass $reflection): Origin
    {
        return match (true) {
            $reflection === null => Origin::None,
            str_starts_with($reflection->getName(), 'Illuminate\\') => Origin::Framework,
            $reflection->getFileName() === false,
            str_contains($reflection->getFileName(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR) => Origin::Vendor,
            default => Origin::App,
        };
    }

    /**
     * The first line of prose in a class's doc block.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    protected function summary(ReflectionClass $reflection): ?string
    {
        $doc = $reflection->getDocComment();

        if ($doc === false) {
            return null;
        }

        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            // Both ends, so a one-line `/** Binds the index. */` reads too.
            $line = trim(preg_replace(['#^\s*(/\*\*|\*/|\*)#', '#\*/\s*$#'], '', $line) ?? '');

            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            return $line;
        }

        return null;
    }
}
