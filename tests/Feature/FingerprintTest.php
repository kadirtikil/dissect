<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use KdrDev\Dissect\SchemaExporter;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The change signal the viewer polls.
 *
 * The graph is built from two sources with different lifecycles — relations
 * from the model files, columns from the live database — so the fingerprint
 * has to move for either, and specifically must *not* move for a migration
 * that has only been written.
 */
class FingerprintTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_is_stable_when_nothing_changes(): void
    {
        $exporter = $this->app->make(SchemaExporter::class);

        $this->assertSame($exporter->fingerprint(), $exporter->fingerprint());
    }

    #[Test]
    public function it_moves_when_a_migration_is_applied(): void
    {
        $exporter = $this->app->make(SchemaExporter::class);
        $before = $exporter->fingerprint();

        // What `migrate` does once a migration's up() has returned. The row is
        // the signal precisely because Laravel only writes it on success.
        DB::table('migrations')->insert([
            'migration' => '2026_07_21_000000_add_a_column',
            'batch' => 99,
        ]);

        $this->assertNotSame($before, $exporter->fingerprint());
    }

    #[Test]
    public function it_ignores_a_migration_that_has_not_run(): void
    {
        $exporter = $this->app->make(SchemaExporter::class);
        $before = $exporter->fingerprint();

        // Writing the file is not the event we care about: the columns it
        // describes do not exist yet, so re-exporting now would show a schema
        // that no database has.
        $path = $this->app->databasePath('migrations/2026_07_21_000000_pending.php');
        @mkdir(dirname($path), recursive: true);
        file_put_contents($path, '<?php // pending');

        try {
            $this->assertSame($before, $exporter->fingerprint());
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function it_moves_when_a_model_file_changes(): void
    {
        $exporter = $this->app->make(SchemaExporter::class);
        $before = $exporter->fingerprint();

        // Relations live in the models, so an edit there is its own signal —
        // no migration involved.
        $model = __DIR__.'/../../workbench/app/Models/Post.php';
        $mtime = filemtime($model);
        touch($model, time() + 60);
        clearstatcache(true, $model);

        try {
            $this->assertNotSame($before, $exporter->fingerprint());
        } finally {
            touch($model, $mtime);
        }
    }

    #[Test]
    public function it_reports_the_current_fingerprint_over_http(): void
    {
        $expected = $this->app->make(SchemaExporter::class)->fingerprint();

        $this->get('/dissect/fingerprint')
            ->assertOk()
            ->assertJson(['fingerprint' => $expected])
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
