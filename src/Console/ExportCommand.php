<?php

namespace KdrDev\Dissect\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use KdrDev\Dissect\Digest\Markdown;
use KdrDev\Dissect\Digest\Mermaid;
use KdrDev\Dissect\OpenApi\Document;
use KdrDev\Dissect\OpenApi\Generator;
use KdrDev\Dissect\Support\Surfaces;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `php artisan dissect:export`: what the page shows, without the page.
 *
 * For a terminal, a script or an agent. JSON is the same payload the page
 * reads, so anything built on one works on the other. Markdown is a digest of
 * it: one line per thing, short enough to read or to paste into a prompt.
 * Mermaid draws the models as an ER diagram for a README or a pull request.
 * OpenAPI describes the API routes for anything that reads an API spec.
 */
class ExportCommand extends Command
{
    protected $signature = 'dissect:export
        {surface=all : schema, routes, jobs, providers, domains, classes, boundaries, or all}
        {--format=json : json, md for a digest to read, mermaid for the models as a diagram, or openapi for the API routes}
        {--output= : Write to this file instead of the terminal}';

    protected $description = 'Export the models, routes, jobs, providers, domains, class references or boundary violations dissect reads';

    public function handle(Surfaces $surfaces, Filesystem $files, Markdown $markdown, Mermaid $mermaid, Generator $openapi): int
    {
        $surface = (string) $this->argument('surface');
        $format = (string) $this->option('format');

        if ($surface !== 'all' && ! Surfaces::has($surface)) {
            $this->components->error("There is no surface called \"{$surface}\". Pick one of: ".implode(', ', [...Surfaces::NAMES, 'all']).'.');

            return self::INVALID;
        }

        if (! in_array($format, $this->formats(), true)) {
            $this->components->error("dissect cannot write \"{$format}\". Pick one of: ".implode(', ', $this->formats()).'.');

            return self::INVALID;
        }

        if ($format === 'mermaid' && $surface !== 'schema') {
            $this->components->error('Mermaid draws the models only. Run: php artisan dissect:export schema --format=mermaid');

            return self::INVALID;
        }

        if ($format === 'openapi' && $surface !== 'routes') {
            $this->components->error('OpenAPI describes the routes only. Run: php artisan dissect:export routes --format=openapi');

            return self::INVALID;
        }

        $names = $surface === 'all' ? Surfaces::NAMES : [$surface];

        try {
            $payloads = [];

            foreach ($names as $name) {
                $payloads[$name] = $surfaces->export($name);
            }
        } catch (Throwable $e) {
            $this->components->error("Could not read the {$name}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $text = match ($format) {
            'md' => implode("\n", array_map($markdown->render(...), array_keys($payloads), $payloads)),
            'mermaid' => $mermaid->schema($payloads['schema']),
            'openapi' => Document::encode($openapi->document()),
            default => $this->json($surface === 'all' ? $payloads : reset($payloads)),
        };

        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            $files->ensureDirectoryExists(dirname($output));
            $files->put($output, $text);

            $this->components->info("Wrote {$surface} as {$format} to {$output}.");

            return self::SUCCESS;
        }

        // Raw, so nothing a payload contains is read as console markup.
        $this->output->write($text, false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    protected function formats(): array
    {
        return ['json', 'md', 'mermaid', 'openapi'];
    }

    /** @param  array<string, mixed>  $data */
    protected function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
