<?php

namespace KdrDev\Dissect\Tests\Digest;

use KdrDev\Dissect\Digest\Mermaid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MermaidTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     */
    protected function diagram(array $nodes, array $edges = []): string
    {
        return (new Mermaid)->schema(['nodes' => $nodes, 'edges' => $edges]);
    }

    /** @param  array<int, array<string, mixed>>  $columns */
    protected function node(string $id, array $columns = []): array
    {
        return ['id' => $id, 'class' => $id, 'table' => 'x', 'columns' => $columns];
    }

    protected function edge(string $source, string $type, string $target, string $name, ?array $keys = null): array
    {
        return ['source' => $source, 'type' => $type, 'target' => $target, 'name' => $name] + ($keys === null ? [] : ['keys' => $keys + ['source' => null, 'target' => null, 'via' => null]]);
    }

    #[Test]
    public function a_model_is_an_entity_with_its_columns_and_keys(): void
    {
        $out = $this->diagram(
            [$this->node('App\Invoice', [
                ['name' => 'id', 'label' => 'bigint', 'increments' => true, 'unique' => true],
                ['name' => 'number', 'label' => 'varchar(32)', 'unique' => true],
                ['name' => 'total', 'label' => 'numeric(8,2)', 'nullable' => true],
                ['name' => 'customer_id', 'label' => 'bigint'],
            ])],
            [$this->edge('App\Invoice', 'BelongsTo', 'App\Customer', 'customer', ['source' => 'customer_id', 'target' => 'id'])],
        );

        $this->assertStringStartsWith("erDiagram\n    Invoice {\n", $out);
        $this->assertStringContainsString('        bigint id PK', $out);
        // Mermaid takes a type as one word, so the length or precision goes.
        $this->assertStringContainsString('        varchar number UK', $out);
        $this->assertStringContainsString('        numeric total "nullable"', $out);
        $this->assertStringContainsString('        bigint customer_id FK', $out);
    }

    #[Test]
    public function a_model_with_no_columns_is_still_drawn(): void
    {
        $this->assertSame("erDiagram\n    Tag {\n    }\n", $this->diagram([$this->node('App\Tag')]));
    }

    #[Test]
    public function a_relation_and_its_inverse_are_one_line(): void
    {
        $out = $this->diagram(
            [$this->node('App\Customer'), $this->node('App\Invoice')],
            [
                $this->edge('App\Customer', 'HasMany', 'App\Invoice', 'invoices', ['source' => 'id', 'target' => 'customer_id']),
                $this->edge('App\Invoice', 'BelongsTo', 'App\Customer', 'customer', ['source' => 'customer_id', 'target' => 'id']),
                // A second belongs-to on other columns is a relation of its own.
                $this->edge('App\Invoice', 'BelongsTo', 'App\Customer', 'payer', ['source' => 'payer_id', 'target' => 'id']),
            ],
        );

        $this->assertStringContainsString('    Customer ||--o{ Invoice : "invoices / customer"', $out);
        $this->assertStringContainsString('    Invoice }o--|| Customer : "payer"', $out);
        $this->assertSame(2, substr_count($out, ' : "'));
    }

    #[Test]
    public function a_many_to_many_seen_from_both_ends_is_one_line(): void
    {
        $out = $this->diagram(
            [$this->node('App\Post'), $this->node('App\Tag')],
            [
                $this->edge('App\Post', 'MorphToMany', 'App\Tag', 'tags', ['source' => 'id', 'target' => 'id', 'via' => 'taggables']),
                $this->edge('App\Tag', 'MorphedByMany', 'App\Post', 'posts', ['source' => 'id', 'target' => 'id', 'via' => 'taggables']),
            ],
        );

        $this->assertStringContainsString('    Post }o--o{ Tag : "tags / posts"', $out);
        $this->assertSame(1, substr_count($out, ' : "'));
    }

    #[Test]
    public function relations_without_keys_are_drawn_as_declared(): void
    {
        $out = $this->diagram(
            [$this->node('App\Customer'), $this->node('App\Invoice')],
            [
                $this->edge('App\Customer', 'HasMany', 'App\Invoice', 'invoices'),
                $this->edge('App\Invoice', 'BelongsTo', 'App\Customer', 'customer'),
                $this->edge('App\Customer', 'HasManyThrough', 'App\Payment', 'payments'),
            ],
        );

        $this->assertStringContainsString('    Customer ||--o{ Invoice : "invoices"', $out);
        $this->assertStringContainsString('    Invoice }o--|| Customer : "customer"', $out);
        // Dotted: through a model in between. A model outside the scan is
        // still named, so the line has somewhere to go.
        $this->assertStringContainsString('    Customer ||..o{ Payment : "payments"', $out);
    }

    #[Test]
    public function a_morph_to_has_no_one_model_to_point_at(): void
    {
        $out = $this->diagram(
            [$this->node('App\Comment')],
            [$this->edge('App\Comment', 'MorphTo', 'App\Comment', 'commentable', ['source' => 'commentable_id'])],
        );

        $this->assertStringNotContainsString('commentable"', $out);
    }

    #[Test]
    public function names_shared_across_namespaces_stay_apart(): void
    {
        $out = $this->diagram(
            [$this->node('App\Billing\Invoice'), $this->node('App\Sales\Invoice')],
            [$this->edge('App\Billing\Invoice', 'HasOne', 'App\Sales\Invoice', 'quote')],
        );

        $this->assertStringContainsString('    Billing_Invoice {', $out);
        $this->assertStringContainsString('    Billing_Invoice ||--o| Sales_Invoice : "quote"', $out);
    }
}
