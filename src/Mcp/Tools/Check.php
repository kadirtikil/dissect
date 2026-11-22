<?php

namespace KdrDev\Dissect\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use KdrDev\Dissect\Digest\Markdown;
use KdrDev\Dissect\Mcp\Payloads;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('dissect_check')]
#[Description('Whether the code keeps to the boundary rules in .dissect/domains.json: which domains each domain may use, and which namespaces it must not. Lists every violation with its file, line, and the rule it breaks. Pass the files you changed to hear only about them. Run it after changing classes in a domain, and before finishing.')]
#[IsReadOnly]
#[IsIdempotent]
class Check extends DissectTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'files' => $schema->array()->items($schema->string())->description('Files to report on, relative to the app root or absolute. Leave out to check the whole app.'),
        ];
    }

    public function handle(Request $request, Payloads $payloads, Markdown $markdown): Response
    {
        return $this->answer(function () use ($request, $payloads, $markdown) {
            $files = array_values(array_filter((array) $request->get('files', []), fn (mixed $file) => is_string($file) && trim($file) !== ''));

            return $markdown->check($payloads->check($files));
        });
    }
}
