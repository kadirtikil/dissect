<?php

namespace KdrDev\Dissect\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use KdrDev\Dissect\Digest\Markdown;
use KdrDev\Dissect\Mcp\Lookup;
use KdrDev\Dissect\Mcp\Payloads;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('dissect_provider')]
#[Description('A service provider of the app: each contract it binds and what that resolves to (bind, singleton, scoped, contextual), the providers it registers, the constructor dependencies behind each concrete, and its other side effects. Read from the source, never booted. Without a name, every provider in one line each.')]
#[IsReadOnly]
#[IsIdempotent]
class Provider extends DissectTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Provider class name, short or full (BillingServiceProvider). To find which provider binds a class, use dissect_search. Leave out to list every provider.'),
        ];
    }

    public function handle(Request $request, Payloads $payloads, Markdown $markdown): Response
    {
        return $this->answer(function () use ($request, $payloads, $markdown) {
            $providers = $payloads->get('providers')['providers'] ?? [];
            $query = trim((string) $request->get('name', ''));

            if ($query === '') {
                return $this->index('Service providers', array_map(
                    fn (array $provider) => '- '.$provider['name'].' · '.count($provider['edges'] ?? []).' edges'.(($provider['deferred'] ?? false) ? ' · deferred' : ''),
                    $providers,
                ), 'Ask for one by name for what it binds.');
            }

            $found = Lookup::find($providers, $query, fn (array $provider) => [$provider['id'], $provider['class'], $provider['name']]);

            return $this->matches('provider', $query, array_map(fn (array $provider) => $markdown->provider($provider), $found));
        });
    }
}
