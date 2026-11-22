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

        $this->assertSame(['schema', 'routes', 'jobs', 'providers', 'domains'], array_keys($exported));
        $this->assertContains('Workbench\\App\\Models\\Post', array_column($exported['schema']['nodes'], 'id'));
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
            ->expectsOutputToContain('Pick one of: schema, routes, jobs, providers, domains, all.')
            ->assertExitCode(2);

        $this->artisan('dissect:export', ['surface' => 'routes', '--format' => 'yaml'])
            ->expectsOutputToContain('dissect cannot write "yaml"')
            ->assertExitCode(2);
    }
}
