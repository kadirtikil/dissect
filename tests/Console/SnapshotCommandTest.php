<?php

namespace KdrDev\Dissect\Tests\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use KdrDev\Dissect\Changes\Snapshot;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SnapshotCommandTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/dissect-snapshot-'.bin2hex(random_bytes(4));
        config()->set('dissect.snapshot_path', $this->directory.'/.dissect/snapshot.json');
    }

    protected function tearDown(): void
    {
        @chmod($this->directory.'/.dissect', 0777);
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** @return array{int, string} */
    protected function snapshot(array $options = []): array
    {
        $code = Artisan::call('dissect:snapshot', $options);

        return [$code, Artisan::output()];
    }

    #[Test]
    public function it_writes_the_snapshot_to_the_configured_file(): void
    {
        [$code, $out] = $this->snapshot();

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/Wrote a snapshot of \d+ models, \d+ routes, \d+ jobs and \d+ providers? to /', $out);

        $written = json_decode((string) file_get_contents($this->directory.'/.dissect/snapshot.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Snapshot::VERSION, $written['version']);
        $this->assertArrayHasKey('Workbench\\App\\Models\\Post', $written['models']);
        $this->assertArrayHasKey('POST:api/posts', $written['routes']);
    }

    #[Test]
    public function the_file_is_the_snapshot_as_encoded(): void
    {
        $this->snapshot();

        $this->assertSame(
            Snapshot::encode($this->app->make(Snapshot::class)->take()),
            file_get_contents($this->directory.'/.dissect/snapshot.json'),
        );
    }

    #[Test]
    public function a_second_run_with_nothing_changed_leaves_the_file_alone(): void
    {
        $this->snapshot();
        $file = $this->directory.'/.dissect/snapshot.json';
        touch($file, $before = time() - 3600);
        clearstatcache();

        [$code, $out] = $this->snapshot();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing changed since the snapshot in ', $out);
        clearstatcache();
        $this->assertSame($before, filemtime($file));
    }

    #[Test]
    public function a_snapshot_out_of_date_is_replaced_without_leaving_anything_beside_it(): void
    {
        mkdir($this->directory.'/.dissect', 0777, true);
        file_put_contents($this->directory.'/.dissect/snapshot.json', '{"version": 1, "models": {}}');

        [$code, $out] = $this->snapshot();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Wrote a snapshot', $out);
        $this->assertSame(['snapshot.json'], array_values(array_diff(scandir($this->directory.'/.dissect'), ['.', '..'])));
    }

    #[Test]
    public function output_writes_it_somewhere_else(): void
    {
        $elsewhere = $this->directory.'/elsewhere/snapshot.json';

        [$code, $out] = $this->snapshot(['--output' => $elsewhere]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString(" to {$elsewhere}.", $out);
        $this->assertFileExists($elsewhere);
        $this->assertFileDoesNotExist($this->directory.'/.dissect/snapshot.json');

        unlink($elsewhere);
        rmdir(dirname($elsewhere));
    }

    #[Test]
    public function a_file_inside_the_app_is_named_relative_to_it(): void
    {
        $inside = base_path('.dissect-test-'.bin2hex(random_bytes(3)).'/snapshot.json');

        try {
            [, $out] = $this->snapshot(['--output' => $inside]);

            $this->assertStringContainsString(' to '.substr($inside, strlen(base_path()) + 1).'.', $out);
        } finally {
            @unlink($inside);
            @rmdir(dirname($inside));
        }
    }

    #[Test]
    public function it_says_so_when_the_file_cannot_be_written(): void
    {
        mkdir($this->directory.'/.dissect', 0777, true);
        chmod($this->directory.'/.dissect', 0555);

        try {
            [$code, $out] = $this->snapshot();

            $this->assertSame(1, $code);
            $this->assertStringContainsString('Could not write ', $out);
        } finally {
            chmod($this->directory.'/.dissect', 0777);
        }
    }

    #[Test]
    public function it_says_when_models_columns_could_not_be_read(): void
    {
        // Nothing is migrated here, so no workbench table is there.
        [$code, $out] = $this->snapshot();

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression("/\\d+ models' columns could not be read: .*Post.*Is the database there and migrated\\?/s", $out);
    }
}
