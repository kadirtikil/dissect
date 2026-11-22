<?php

namespace KdrDev\Dissect\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use KdrDev\Dissect\Digest\Markdown;
use KdrDev\Dissect\Mcp\Lookup;
use KdrDev\Dissect\Mcp\Payloads;
use KdrDev\Dissect\Support\ClassNames;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('dissect_model')]
#[Description('An Eloquent model: its table, domain, columns with types and keys, the relations it declares with their key columns, and the models that point at it. Without a name, every model in one line each.')]
#[IsReadOnly]
#[IsIdempotent]
class Model extends DissectTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Class name, short or full (Invoice, App\\Models\\Invoice), or table name. Leave out to list every model.'),
        ];
    }

    public function handle(Request $request, Payloads $payloads, Markdown $markdown): Response
    {
        return $this->answer(function () use ($request, $payloads, $markdown) {
            $schema = $payloads->get('schema');
            $nodes = $schema['nodes'] ?? [];
            $names = ClassNames::short(array_column($nodes, 'id'));
            $query = trim((string) $request->get('name', ''));

            if ($query === '') {
                return $this->index('Models', array_map(
                    fn (array $node) => '- '.$names[$node['id']].' `'.$node['table'].'`'.(isset($node['context']) ? ' · domain '.$node['context'] : ''),
                    $nodes,
                ), 'Ask for one by name for its columns and relations.');
            }

            $found = Lookup::find($nodes, $query, fn (array $node) => [$node['id'], ClassNames::base($node['id']), $names[$node['id']], $node['table']]);

            return $this->matches('model', $query, array_map(fn (array $node) => $markdown->model($node, $schema), $found));
        });
    }
}
