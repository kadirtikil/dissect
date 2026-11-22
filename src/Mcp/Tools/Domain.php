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

#[Name('dissect_domain')]
#[Description('The domains (bounded contexts) of the app: how each was found, the folders it lives in, and with a name, every class in it. Without a name, every domain, plus classes whose domain looks wrong and problems in .dissect/domains.json.')]
#[IsReadOnly]
#[IsIdempotent]
class Domain extends DissectTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Domain name (Billing). Leave out for every domain.'),
        ];
    }

    public function handle(Request $request, Payloads $payloads, Markdown $markdown): Response
    {
        return $this->answer(function () use ($request, $payloads, $markdown) {
            $payload = $payloads->get('domains');
            $query = trim((string) $request->get('name', ''));

            if ($query === '') {
                return $markdown->domains($payload);
            }

            $found = Lookup::find($payload['domains'] ?? [], $query, fn (array $domain) => [$domain['name']]);

            return $this->matches('domain', $query, array_map(fn (array $domain) => $markdown->domain($domain), $found));
        });
    }
}
