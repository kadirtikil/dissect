<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use KdrDev\Dissect\Tests\Concerns\UsesDomainFixture;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * domains.json puts a domain on every row, but no surface's fingerprint
 * watches it. Editing it must still not be answered from the cache.
 */
class DomainCacheTest extends TestCase
{
    use UsesDomainFixture {
        defineEnvironment as fixtureEnvironment;
    }

    protected string $cache;

    protected string $file;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir().'/dissect-cache-'.uniqid();
        $this->file = sys_get_temp_dir().'/dissect-domains-'.uniqid().'.json';
        file_put_contents($this->file, '{"domains": []}');
        touch($this->file, time() - 100);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->cache);
        @unlink($this->file);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        $this->fixtureEnvironment($app);

        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file.path', $this->cache);
        $app['config']->set('dissect.domains.file', $this->file);
    }

    protected function quote(): ?string
    {
        return collect($this->getJson('/dissect/schema.json')->assertOk()->json('nodes'))
            ->firstWhere('id', 'Workbench\\Domains\\Domain\\Sales\\Models\\Quote')['context'];
    }

    #[Test]
    public function editing_domains_json_is_not_answered_from_the_cache(): void
    {
        $this->assertSame('Sales', $this->quote());

        file_put_contents($this->file, json_encode(['domains' => [
            ['name' => 'Pricing', 'paths' => [$this->domainFixture().'/app/Domain/Sales/Models/Quote.php']],
        ]]));
        touch($this->file, time());
        clearstatcache();

        $this->refreshApplication();

        $this->assertSame('Pricing', $this->quote());
    }
}
