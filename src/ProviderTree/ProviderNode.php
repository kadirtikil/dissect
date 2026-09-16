<?php

namespace KdrDev\Dissect\ProviderTree;

/**
 * One box in a provider's tree.
 *
 * The `id` is what edges point at, and it is the container name rather than a
 * counter: the same contract reached twice — bound by the provider and
 * type-hinted by one of its concretes — has to be one node with two edges into
 * it, not two boxes saying the same thing. A counter would make that a
 * bookkeeping problem; the name makes it fall out.
 *
 * `depth` is how far from the provider this was first reached, which is what
 * the layout tiers on and what the depth cap counts.
 */
final readonly class ProviderNode
{
    /**
     * @param  string  $id  Container name or class string; unique within a tree.
     * @param  string|null  $class  The class this resolves to, when it is a class at all.
     * @param  string|null  $file  Project-relative, for a class the application owns.
     * @param  string|null  $summary  First line of the doc block, when there is one.
     * @param  string  $confidence  How firmly this node was established — see {@see \KdrDev\Dissect\Jobs\Confidence}.
     */
    public function __construct(
        public string $id,
        public NodeKind $kind,
        public string $label,
        public Origin $origin,
        public int $depth,
        public string $confidence,
        public ?string $class = null,
        public ?string $file = null,
        public ?string $summary = null,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     kind: string,
     *     label: string,
     *     origin: string,
     *     depth: int,
     *     confidence: string,
     *     class: string|null,
     *     file: string|null,
     *     summary: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'origin' => $this->origin->value,
            'depth' => $this->depth,
            'confidence' => $this->confidence,
            'class' => $this->class,
            'file' => $this->file,
            'summary' => $this->summary,
        ];
    }
}
