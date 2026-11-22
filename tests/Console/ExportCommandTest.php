<?php

namespace KdrDev\Dissect\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ExportCommandTest extends TestCase
{
    /** @return array<string, mixed> */
    protected function exported(array $arguments): array
    {
        $this->assertSame(0, Artisan::call('dissect:export', $arguments));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function it_prints_the_payload_the_page_reads(): void
    {
        $exported = $this->exported(['surface' => 'routes']);
        $served = $this->getJson('/dissect/routes.json')->assertOk()->json();

        $this->assertSame(array_column($served['routes'], 'id'), array_column($exported['routes'], 'id'));
    }

    #[Test]
    public function all_is_every_surface_by_name(): void
    {
        $exported = $this->exported([]);

        $this->assertSame(['schema', 'routes', 'jobs', 'providers', 'domains', 'classes', 'boundaries'], array_keys($exported));
        $this->assertContains('Workbench\\App\\Models\\Post', array_column($exported['schema']['nodes'], 'id'));
    }

    #[Test]
    public function md_is_a_digest_of_the_same_payload(): void
    {
        $this->assertSame(0, Artisan::call('dissect:export', ['surface' => 'schema', '--format' => 'md']));
        $digest = Artisan::output();

        $this->assertStringStartsWith("# Models\n", $digest);
        $this->assertStringContainsString("## Post `posts`\n`Workbench\\App\\Models\\Post`", $digest);
        $this->assertStringNotContainsString('{', $digest);
    }

    #[Test]
    public function md_for_all_is_every_digest_in_turn(): void
    {
        $this->assertSame(0, Artisan::call('dissect:export', ['--format' => 'md']));
        $digest = Artisan::output();

        $headings = array_values(array_filter(explode("\n", $digest), fn (string $line) => str_starts_with($line, '# ')));

        $this->assertSame(['# Models', '# Routes', '# Jobs', '# Service providers', '# Domains', '# Classes', '# Boundaries'], $headings);
    }

    #[Test]
    public function mermaid_draws_the_models(): void
    {
        $this->assertSame(0, Artisan::call('dissect:export', ['surface' => 'schema', '--format' => 'mermaid']));
        $diagram = Artisan::output();

        $this->assertStringStartsWith("erDiagram\n", $diagram);
        $this->assertStringContainsString("    Post {\n", $diagram);
    }

    #[Test]
    public function mermaid_is_refused_for_anything_but_the_models(): void
    {
        $this->artisan('dissect:export', ['surface' => 'routes', '--format' => 'mermaid'])
            ->expectsOutputToContain('Mermaid draws the models only.')
            ->assertExitCode(2);

        // All is the default surface, so a bare --format=mermaid lands here too.
        $this->artisan('dissect:export', ['--format' => 'mermaid'])->assertExitCode(2);
    }

    #[Test]
    public function openapi_describes_the_api_routes(): void
    {
        config([
            'app.name' => 'Workbench',
            'dissect.openapi.version' => '2.0.0',
            'dissect.openapi.servers' => ['https://api.example.com'],
        ]);

        $this->assertSame(0, Artisan::call('dissect:export', ['surface' => 'routes', '--format' => 'openapi']));
        $document = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame('Workbench', $document['info']['title']);
        $this->assertSame('2.0.0', $document['info']['version']);
        $this->assertStringContainsString('Nothing here is written by hand', $document['info']['description']);
        $this->assertSame([['url' => 'https://api.example.com']], $document['servers']);
        $this->assertArrayHasKey('/api/posts', $document['paths']);

        // The web stack's pages are not the API, unless asked for.
        $this->assertArrayNotHasKey('/', $document['paths']);

        config(['dissect.openapi.stacks' => ['api', 'web'], 'dissect.openapi.title' => 'Everything']);
        Artisan::call('dissect:export', ['surface' => 'routes', '--format' => 'openapi']);
        $everything = json_decode(Artisan::output(), true);

        $this->assertSame('Everything', $everything['info']['title']);
        $this->assertGreaterThan(count($document['paths']), count($everything['paths']));
    }

    #[Test]
    public function openapi_is_refused_for_anything_but_the_routes(): void
    {
        $this->artisan('dissect:export', ['surface' => 'schema', '--format' => 'openapi'])
            ->expectsOutputToContain('OpenAPI describes the routes only.')
            ->assertExitCode(2);

        $this->artisan('dissect:export', ['--format' => 'openapi'])->assertExitCode(2);
    }

    #[Test]
    public function it_writes_to_a_file_when_asked(): void
    {
        $path = sys_get_temp_dir().'/dissect-export-'.uniqid().'/nested/jobs.json';

        try {
            $this->artisan('dissect:export', ['surface' => 'jobs', '--output' => $path])
                ->expectsOutputToContain('Wrote jobs as json to')
                ->assertSuccessful();

            $this->assertArrayHasKey('jobs', json_decode((string) file_get_contents($path), true));
        } finally {
            @unlink($path);
            @rmdir(dirname($path));
            @rmdir(dirname($path, 2));
        }
    }

    #[Test]
    public function an_unknown_surface_or_format_is_refused_with_the_choices(): void
    {
        $this->artisan('dissect:export', ['surface' => 'queue'])
            ->expectsOutputToContain('Pick one of: schema, routes, jobs, providers, domains, classes, boundaries, all.')
            ->assertExitCode(2);

        $this->artisan('dissect:export', ['surface' => 'routes', '--format' => 'yaml'])
            ->expectsOutputToContain('dissect cannot write "yaml"')
            ->assertExitCode(2);
    }
}
