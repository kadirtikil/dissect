<?php

namespace KdrDev\Dissect\ProviderTree;

/**
 * Everything read about one provider: its tree, and what it does besides.
 *
 * The provider is a node in its own tree rather than a field beside it, so the
 * root is drawn, selected and linked to its file the same way as every box
 * under it. `provider` is that node's id, which is its class.
 *
 * Side effects are counted rather than listed. The badge says a provider
 * listens for three events; which three is something the file answers better
 * than a tooltip would.
 *
 * `partial` is not stored, it is read off the nodes: a tree is partial exactly
 * when something in it could not be named, and a flag kept separately is one
 * that can disagree with the tree it describes.
 *
 * `truncated` is the other way a tree can be incomplete, and a different
 * sentence: nothing was unreadable, the depth or node cap stopped the walk on
 * purpose. There is no node to derive that from — the point is that the nodes
 * past the cap were never added.
 */
final readonly class ProviderDescription
{
    /**
     * @param  class-string  $provider  Id of the root node.
     * @param  array<int, ProviderNode>  $nodes  The root first, then in the order reached.
     * @param  array<int, ProviderEdge>  $edges
     * @param  array<string, int>  $sideEffects  {@see SideEffect} value to how many times it was seen.
     * @param  array<int, string>  $provides  What a deferred provider says it binds, as `provides()` returns it.
     */
    public function __construct(
        public string $provider,
        public array $nodes,
        public array $edges,
        public array $sideEffects = [],
        public bool $deferred = false,
        public array $provides = [],
        public bool $truncated = false,
    ) {}

    /** Whether any branch of the tree stopped at something it could not name. */
    public function partial(): bool
    {
        foreach ($this->nodes as $node) {
            if ($node->kind === NodeKind::Unresolved) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     provider: string,
     *     nodes: array<int, array<string, mixed>>,
     *     edges: array<int, array<string, mixed>>,
     *     side_effects: array<string, int>,
     *     deferred: bool,
     *     provides: array<int, string>,
     *     partial: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'nodes' => array_map(fn (ProviderNode $node) => $node->toArray(), $this->nodes),
            'edges' => array_map(fn (ProviderEdge $edge) => $edge->toArray(), $this->edges),
            'side_effects' => $this->sideEffects,
            'deferred' => $this->deferred,
            'provides' => $this->provides,
            'partial' => $this->partial(),
            'truncated' => $this->truncated,
        ];
    }
}
