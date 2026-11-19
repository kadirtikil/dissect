<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use KdrDev\Dissect\Jobs\JobExporter;
use KdrDev\Dissect\ProviderTree\ProviderExporter;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * An export is cached against a fingerprint of the files it reads. These are
 * the changes that fingerprint cannot see, and that must still not be answered
 * from the cache.
 */
class CacheInvalidationTest extends TestCase
{
    protected string $cache;

    protected string $scanned;

    protected function setUp(): void
    {
        // Set before the application boots, so every refresh below shares one
        // on-disk cache — the way consecutive requests share one in an app.
        $this->cache = sys_get_temp_dir().'/dissect-cache-'.uniqid();
        $this->scanned = sys_get_temp_dir().'/dissect-scanned-'.uniqid();
        mkdir($this->scanned, 0755, true);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->cache);
        (new Filesystem)->deleteDirectory($this->scanned);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file.path', $this->cache);
    }

    #[Test]
    public function a_config_change_is_not_answered_from_the_cache(): void
    {
        $this->assertNotEmpty($this->getJson('/dissect/schema.json')->json('nodes'));

        // A new request with the namespace corrected — or, here, broken. No
        // model file moved and no migration ran, so the fingerprint is the same.
        $this->refreshApplication();
        config()->set('dissect.models_namespace', 'Nowhere\\Models');

        $this->assertSame([], $this->getJson('/dissect/schema.json')->json('nodes'));
    }

    #[Test]
    public function the_same_config_is_still_answered_from_the_cache(): void
    {
        $this->getJson('/dissect/schema.json')->assertOk();
        $written = count((new Filesystem)->allFiles($this->cache));

        $this->assertGreaterThan(0, $written, 'the export was written to the shared cache');

        $this->refreshApplication();
        $this->getJson('/dissect/schema.json')->assertOk();

        // Nothing changed, so the second request read the entry the first one
        // wrote rather than adding one beside it.
        $this->assertSame($written, count((new Filesystem)->allFiles($this->cache)));
    }

    #[Test]
    public function a_provider_directory_outside_the_watched_paths_still_moves_the_signal(): void
    {
        // A monorepo package: scanned for providers, nowhere under `app`.
        $this->rewire('providers');
        $before = $this->app->make(ProviderExporter::class)->fingerprint();

        file_put_contents($this->scanned.'/BillingServiceProvider.php', '<?php // added');

        $this->rewire('providers');
        $this->assertNotSame($before, $this->app->make(ProviderExporter::class)->fingerprint());
    }

    #[Test]
    public function a_job_directory_outside_the_watched_paths_still_moves_the_signal(): void
    {
        $this->rewire('jobs');
        $before = $this->app->make(JobExporter::class)->fingerprint();

        file_put_contents($this->scanned.'/ChargeInvoice.php', '<?php // added');

        $this->rewire('jobs');
        $this->assertNotSame($before, $this->app->make(JobExporter::class)->fingerprint());
    }

    /** Scan the temporary directory, watch one that never changes, and rebuild the exporter. */
    protected function rewire(string $surface): void
    {
        config()->set("dissect.{$surface}.paths", [$this->scanned]);
        config()->set("dissect.{$surface}.watch_paths", [__DIR__.'/../../workbench/routes']);

        $this->app->forgetInstance($surface === 'jobs' ? JobExporter::class : ProviderExporter::class);
    }
}
