<?php

namespace KdrDev\Dissect\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use KdrDev\Dissect\Changes\Baselines;
use KdrDev\Dissect\Digest\Markdown;
use KdrDev\Dissect\Mcp\Payloads;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[Name('dissect_changes')]
#[Description('What changed in the models, routes, jobs and service providers since a baseline: since the user started watching, or since the committed .dissect/snapshot.json. Each change is marked breaking, risky or additive for whoever already depends on it, e.g. a removed response field or a newly required request field is breaking. Call it before finishing, to review what your changes did to the app\'s surface and to say so.')]
#[IsReadOnly]
#[IsIdempotent]
class Changes extends DissectTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'against' => $schema->string()->enum(Baselines::NAMES)->description('watch: since the user started watching. snapshot: since the committed .dissect/snapshot.json. Leave out for watch if the user is watching, else snapshot.'),
        ];
    }

    public function handle(Request $request, Payloads $payloads, Markdown $markdown, Baselines $baselines): Response
    {
        $asked = $request->get('against');
        $against = is_string($asked) && Baselines::has($asked) ? $asked : $baselines->preferred();

        if ($against === null) {
            return Response::text(
                "There is nothing to compare against yet: no snapshot is committed, and nobody is watching.\n"
                ."dissect only reads, so it cannot take one. Ask the user to start watching on the dissect page, or to run php artisan dissect:snapshot --watch (or dissect:snapshot for a snapshot to commit).\n"
            );
        }

        return $this->answer(function () use ($payloads, $markdown, $against) {
            try {
                $report = $payloads->changes($against);
            } catch (RuntimeException $e) {
                // Not a failure to read the app: a baseline that cannot be read.
                return $e->getMessage()."\n";
            }

            return $markdown->changes($report, ($against === Baselines::WATCH ? 'watching started' : $report['against']));
        });
    }
}
