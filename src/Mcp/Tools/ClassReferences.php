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

#[Name('dissect_class')]
#[Description('Any class, interface, trait or enum in the app: its domain and file, every other app class it uses, and every app class that uses it, each with how (extends, implements, relation, dispatch, event, binding, new, static, type, ...) and on which lines. Use it before changing a class, to see what depends on it. Without a name, how the domains depend on each other and every class in one line each.')]
#[IsReadOnly]
#[IsIdempotent]
class ClassReferences extends DissectTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Class name, short or full (InvoiceService, App\\Services\\InvoiceService). Leave out for the overview.'),
        ];
    }

    public function handle(Request $request, Payloads $payloads, Markdown $markdown): Response
    {
        return $this->answer(function () use ($request, $payloads, $markdown) {
            $payload = $payloads->get('classes');
            $classes = $payload['classes'] ?? [];
            $query = trim((string) $request->get('name', ''));

            if ($query === '') {
                return $markdown->classes($payload);
            }

            $names = ClassNames::short(array_column($classes, 'id'));
            $found = Lookup::find($classes, $query, fn (array $class) => [$class['id'], ClassNames::base($class['id']), $names[$class['id']]]);

            return $this->matches('class', $query, array_map(fn (array $class) => $markdown->class($class, $payload), $found));
        });
    }
}
