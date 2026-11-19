<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Each edge names the columns that tie its two models together, so the graph
 * can draw `posts.author_id` → `authors.id` rather than only Post → Author.
 */
class RelationKeysTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, array{source: ?string, target: ?string, via: ?string}}>
     */
    public static function relations(): array
    {
        return [
            'belongs to' => ['Post', 'author', ['source' => 'author_id', 'target' => 'id', 'via' => null]],
            'belongs to itself' => ['Category', 'parent', ['source' => 'parent_id', 'target' => 'id', 'via' => null]],
            'has many' => ['Author', 'posts', ['source' => 'id', 'target' => 'author_id', 'via' => null]],
            'has one' => ['Author', 'profile', ['source' => 'id', 'target' => 'author_id', 'via' => null]],
            'morph many' => ['Post', 'comments', ['source' => 'id', 'target' => 'commentable_id', 'via' => null]],
            // Which model owns the row differs per row, so the far end is unknown.
            'morph to' => ['Comment', 'commentable', ['source' => 'commentable_id', 'target' => null, 'via' => null]],
            'belongs to many' => ['Post', 'editors', ['source' => 'id', 'target' => 'id', 'via' => 'author_post']],
            'morph to many' => ['Post', 'tags', ['source' => 'id', 'target' => 'id', 'via' => 'taggables']],
            'has many through' => ['Country', 'posts', ['source' => 'id', 'target' => 'author_id', 'via' => 'authors']],
        ];
    }

    /** @param  array{source: ?string, target: ?string, via: ?string}  $keys */
    #[Test]
    #[DataProvider('relations')]
    public function it_names_the_columns_behind_a_relation(string $model, string $relation, array $keys): void
    {
        $this->assertSame($keys, $this->edge($model, $relation)['keys']);
    }

    #[Test]
    public function every_named_column_exists_on_its_model(): void
    {
        $schema = $this->get('/dissect/schema.json')->assertOk()->json();
        $columns = collect($schema['nodes'])->mapWithKeys(
            fn (array $node) => [$node['id'] => array_column($node['columns'], 'name')],
        );

        foreach ($schema['edges'] as $edge) {
            $where = "{$edge['source']}::{$edge['name']}";

            if ($edge['keys']['source'] !== null) {
                $this->assertContains($edge['keys']['source'], $columns[$edge['source']], $where);
            }

            // A relation to a model outside the scan has no columns to check.
            if ($edge['keys']['target'] !== null && $columns->has($edge['target'])) {
                $this->assertContains($edge['keys']['target'], $columns[$edge['target']], $where);
            }
        }
    }

    /** @return array<string, mixed> */
    protected function edge(string $model, string $relation): array
    {
        $schema = $this->get('/dissect/schema.json')->assertOk()->json();

        $edge = collect($schema['edges'])->first(
            fn (array $edge) => $edge['source'] === 'Workbench\\App\\Models\\'.$model && $edge['name'] === $relation,
        );

        $this->assertNotNull($edge, "{$model}::{$relation} should be exported");

        return $edge;
    }
}
