<?php

namespace KdrDev\Dissect\ProviderTree;

use Closure;
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
 *
 * A provider registered by the one being described is read into the same tree
 * via {@see within()}: while it is read, depths are relative to *its* node and
 * arrows leave from it, so the inspector reads a nested provider exactly the
 * way it reads the root.
 */
final class TreeBuilder
{
    /** Firmest first — the index is the ordering, as in {@see \KdrDev\Dissect\Jobs\Confidence}. */
    protected const LADDER = ['certain', 'inferred', 'unknown'];

    /** Set by the inspector once the root provider has been read. */
    public bool $deferred = false;

    /** @var array<int, string> */
    public array $provides = [];

    /** Whether a depth or node cap cut something off. */
    public bool $truncated = false;

    /** @var array<string, ProviderNode> */
    protected array $nodes = [];

    /** @var array<string, ProviderEdge> */
    protected array $edges = [];

    /** @var array<string, int> */
    protected array $sideEffects = [];

    /** @var array<string, true> Providers already read into this tree. */
    protected array $read = [];

    protected int $unresolved = 0;

    protected string $current;

    protected int $offset = 0;

    /** @param  class-string  $provider */
    public function __construct(public readonly string $provider)
    {
        $this->named($provider, NodeKind::Provider, 0, 'certain');
        $this->current = $provider;
        $this->read[$provider] = true;
    }

    /** The provider whose calls are being read — arrows leave from it. */
    public function current(): string
    {
        return $this->current;
    }

    public function isRoot(): bool
    {
        return $this->current === $this->provider;
    }

    /**
     * Read a registered provider into this tree, from its own node.
     *
     * Each provider is read once per tree: a registration cycle, or two
     * providers registering the same third, is an arrow to a node already
     * drawn rather than a second copy of its bindings.
     *
     * @param  Closure(): void  $read
     */
    public function within(string $provider, Closure $read): void
    {
        $node = $this->nodes[$provider] ?? null;

        if ($node === null || isset($this->read[$provider])) {
            return;
        }

        $this->read[$provider] = true;

        [$current, $offset] = [$this->current, $this->offset];
        [$this->current, $this->offset] = [$provider, $node->depth];

        try {
            $read();
        } finally {
            [$this->current, $this->offset] = [$current, $offset];
        }
    }

    /**
     * A node for a class or container key, returning its id.
     *
     * Everything about the node other than its position — label, origin, file,
     * summary — is read off the name itself, so every caller describes a class
     * the same way.
     *
     * `$depth` is relative to the provider being read.
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
            depth: $this->offset + $depth,
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

    /** Whether a name is a class, interface or enum that can be reflected. */
    public function exists(string $name): bool
    {
        return $this->reflect(ltrim($name, '\\')) !== null;
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
            depth: $this->offset + $depth,
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

    /**
     * Counted for the root only: the badges sit on the provider being
     * described, and a registered provider's config merge is not something the
     * root does.
     */
    public function sideEffect(SideEffect $effect): void
    {
        if ($this->isRoot()) {
            $this->sideEffects[$effect->value] = ($this->sideEffects[$effect->value] ?? 0) + 1;
        }
    }

    public function node(string $id): ?ProviderNode
    {
        return $this->nodes[$id] ?? null;
    }

    /** @return array<int, ProviderNode> */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function count(): int
    {
        return count($this->nodes);
    }

    /**
     * Where arrows of the given kinds lead from one node.
     *
     * @param  array<int, EdgeKind>  $kinds
     * @return array<int, string>
     */
    public function targets(string $source, array $kinds): array
    {
        $targets = [];

        foreach ($this->edges as $edge) {
            if ($edge->source === $source && in_array($edge->kind, $kinds, true)) {
                $targets[] = $edge->target;
            }
        }

        return array_values(array_unique($targets));
    }

    public function build(): ProviderDescription
    {
        return new ProviderDescription(
            provider: $this->provider,
            nodes: array_values($this->nodes),
            edges: array_values($this->edges),
            sideEffects: $this->sideEffects,
            deferred: $this->deferred,
            provides: $this->provides,
            truncated: $this->truncated,
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
