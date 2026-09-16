<?php

namespace KdrDev\Dissect\ProviderTree;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use SplQueue;

/**
 * Follows the concretes a provider binds into their own constructors.
 *
 * A list of bindings says what a provider decides; the constructors say what
 * those decisions pull in, which is the part nobody can see from the provider
 * file. Read by reflection only — a signature is a declaration, and nothing is
 * resolved from the container or constructed to find it.
 *
 * Breadth-first, so every class is expanded from the shallowest place it is
 * reached: that is the depth the tree draws it at, and the depth the cap is
 * counted against. A class is expanded once per tree — its constructor says
 * the same thing wherever it is reached from, and "once" is also what ends a
 * cycle: the arrow back to a class already expanded is drawn, and the walk
 * does not go round again.
 *
 * The walk stops at code the application does not own. A constructor
 * type-hinting `Illuminate\Filesystem\Filesystem` gets the node and the arrow,
 * and the framework's own dependencies stay out of a tree about this app.
 *
 * An interface is followed through the bindings already in the tree: if this
 * provider binds the contract, whatever it is bound to is expanded next. If it
 * does not, the contract is a leaf — some other provider decides it, and
 * guessing which would be reporting the runtime container as a static fact.
 */
class DependencyResolver
{
    /** Arrows from a contract to what the provider decided it resolves to. */
    protected const DECISIONS = [
        EdgeKind::Bind,
        EdgeKind::Singleton,
        EdgeKind::Scoped,
        EdgeKind::Instance,
        EdgeKind::Contextual,
    ];

    protected int $maxDepth;

    protected int $maxNodes;

    /**
     * Caps default to the configured ones; the arguments are there for a test
     * that wants to see a cut-off without building a fixture that deep.
     */
    public function __construct(?int $maxDepth = null, ?int $maxNodes = null)
    {
        $this->maxDepth = $maxDepth ?? (int) config('dissect.providers.max_depth', 4);
        $this->maxNodes = $maxNodes ?? (int) config('dissect.providers.max_nodes', 150);
    }

    public function resolve(TreeBuilder $tree): void
    {
        /** @var SplQueue<string> $queue */
        $queue = new SplQueue;

        /** @var array<string, true> $expanded */
        $expanded = [];

        $nodes = $tree->nodes();

        // Shallowest first, so the queue starts in the order a breadth-first
        // walk from the root would have reached them.
        usort($nodes, fn (ProviderNode $a, ProviderNode $b) => $a->depth <=> $b->depth);

        foreach ($nodes as $node) {
            if ($node->kind === NodeKind::Concrete) {
                $queue->enqueue($node->id);
            }
        }

        while (! $queue->isEmpty()) {
            $id = $queue->dequeue();
            $node = $tree->node($id);

            if ($node === null || isset($expanded[$id]) || $node->origin !== Origin::App) {
                continue;
            }

            $expanded[$id] = true;

            if ($node->kind === NodeKind::Contract) {
                // What this provider bound the contract to, one tier further down.
                foreach ($tree->targets($id, self::DECISIONS) as $target) {
                    $queue->enqueue($target);
                }

                continue;
            }

            if ($node->kind !== NodeKind::Concrete || $node->class === null) {
                continue;
            }

            foreach ((new ReflectionClass($node->class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                $child = $this->parameter($tree, $node, $parameter);

                if ($child !== null) {
                    $queue->enqueue($child);
                }
            }
        }
    }

    /**
     * One constructor parameter as a node and an `injects` arrow, returning the
     * id to walk into next.
     */
    protected function parameter(TreeBuilder $tree, ProviderNode $owner, ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        $depth = $owner->depth + 1;

        // A scalar is configuration, not a dependency, and so is an untyped
        // parameter with a default — the container fills neither from a binding.
        $classes = match (true) {
            $type === null => null,
            $type instanceof ReflectionNamedType => $type->isBuiltin() ? [] : [$type->getName()],
            $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => $this->classesIn($type),
            default => null,
        };

        if ($classes === [] || ($classes === null && $parameter->isOptional())) {
            return null;
        }

        // `self` and `static` point back at the owner, which is already drawn.
        if ($classes !== null && count($classes) === 1 && in_array(strtolower($classes[0]), ['self', 'static'], true)) {
            return null;
        }

        $class = $classes !== null && count($classes) === 1 ? ltrim($classes[0], '\\') : null;

        // A class already in the tree costs an arrow, not a node — so neither
        // cap applies. This is how a cycle closes: the arrow back is drawn, and
        // the queue skips the class because it was expanded.
        if ($class !== null && $tree->has($class)) {
            $tree->edge($owner->id, $class, EdgeKind::Injects, 'inferred');

            return $class;
        }

        if (! $this->room($tree, $depth)) {
            return null;
        }

        // Untyped, several classes at once, or a class that does not load: the
        // container has something to inject here, and it cannot be named.
        if ($class === null || ! $tree->exists($class)) {
            $label = '$'.$parameter->getName().($type === null ? '' : ': '.$type);
            $tree->edge($owner->id, $tree->unresolved($label, $depth), EdgeKind::Injects, 'unknown');

            return null;
        }

        $id = $tree->named($class, $tree->kindOf($class), $depth, 'inferred');
        $tree->edge($owner->id, $id, EdgeKind::Injects, 'inferred');

        return $id;
    }

    /**
     * Whether one more node fits under both caps.
     *
     * Hitting either marks the tree truncated, so the page can tell a branch
     * that ends from a branch that was cut.
     */
    protected function room(TreeBuilder $tree, int $depth): bool
    {
        if ($depth > $this->maxDepth || $tree->count() >= $this->maxNodes) {
            $tree->truncated = true;

            return false;
        }

        return true;
    }

    /** @return array<int, string> Every non-builtin type named in a union or intersection. */
    protected function classesIn(ReflectionUnionType|ReflectionIntersectionType $type): array
    {
        $classes = [];

        foreach ($type->getTypes() as $part) {
            if ($part instanceof ReflectionNamedType && ! $part->isBuiltin()) {
                $classes[] = $part->getName();
            }

            // A DNF type — `(A&B)|null` — nests an intersection inside the union.
            if ($part instanceof ReflectionIntersectionType) {
                $classes = [...$classes, ...$this->classesIn($part)];
            }
        }

        return $classes;
    }
}
