<?php

namespace KdrDev\Dissect\ProviderTree;

/**
 * One arrow between two nodes.
 *
 * `consumer` is set only by a contextual binding, which is the one construct
 * carrying three classes rather than two: the contract, what it resolves to,
 * and who has to be asking for that answer to apply. Without it on the edge,
 * `when(ReportMailer)->needs(Renderer)->give(Pdf)` and a plain
 * `bind(Renderer, Pdf)` draw identically while meaning different things.
 */
final readonly class ProviderEdge
{
    /**
     * @param  string  $source  Node id the arrow leaves.
     * @param  string  $target  Node id it arrives at.
     * @param  string|null  $consumer  Class the binding is scoped to, for a contextual edge.
     * @param  string  $confidence  See {@see \KdrDev\Dissect\Jobs\Confidence}.
     */
    public function __construct(
        public string $source,
        public string $target,
        public EdgeKind $kind,
        public string $confidence,
        public ?string $consumer = null,
    ) {}

    /** A stable identity for the arrow, so the frontend does not have to invent one. */
    public function id(): string
    {
        return $this->source.'->'.$this->target.':'.$this->kind->value;
    }

    /**
     * @return array{
     *     id: string,
     *     source: string,
     *     target: string,
     *     kind: string,
     *     confidence: string,
     *     consumer: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'source' => $this->source,
            'target' => $this->target,
            'kind' => $this->kind->value,
            'confidence' => $this->confidence,
            'consumer' => $this->consumer,
        ];
    }
}
