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

#[Name('dissect_job')]
#[Description('A queued class (job, listener, mailable or notification): its queue and where that comes from, retries and timeouts, the models it carries, its middleware, and every place in the code that dispatches it, with the route each one is under. Without a name, every queued class in one line each.')]
#[IsReadOnly]
#[IsIdempotent]
class Job extends DissectTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Class name, short or full (SendInvoice, App\\Jobs\\SendInvoice). Leave out to list every queued class.'),
        ];
    }

    public function handle(Request $request, Payloads $payloads, Markdown $markdown): Response
    {
        return $this->answer(function () use ($request, $payloads, $markdown) {
            $jobs = $payloads->get('jobs')['jobs'] ?? [];
            $query = trim((string) $request->get('name', ''));

            if ($query === '') {
                return $this->index('Jobs', array_map(
                    fn (array $job) => '- '.$job['name'].' ('.$job['kind'].') · queue '.($job['queue'] ?? ($job['queue_source'] === 'mixed' ? 'mixed' : 'default')).(isset($job['context']) ? ' · domain '.$job['context'] : ''),
                    $jobs,
                ), 'Ask for one by name for its retries, payload and dispatch sites.');
            }

            $found = Lookup::find($jobs, $query, fn (array $job) => [$job['id'] ?? null, $job['class'], $job['name']]);

            return $this->matches('queued class', $query, array_map(fn (array $job) => $markdown->job($job), $found));
        });
    }
}
