<?php

namespace KdrDev\Dissect\Tests\Mcp;

use Illuminate\Support\Facades\File;
use KdrDev\Dissect\Mcp\LoadedEnvironment;
use KdrDev\Dissect\Mcp\Payloads;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The MCP server reads .env once; each call's fresh artisan has to read it
 * again, or an edit to it does nothing until the server restarts.
 */
class LoadedEnvironmentTest extends TestCase
{
    protected string $directory;

    /** @var array<int, string> */
    protected array $set = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/dissect-env-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->directory);

        // Stands in for artisan: prints what it was started with.
        File::put($this->directory.'/artisan', '<?php echo json_encode(["database" => getenv("DISSECT_PROBE_DATABASE"), "host" => getenv("DISSECT_PROBE_HOST")]);');
    }

    protected function tearDown(): void
    {
        foreach ($this->set as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** As Laravel sets a variable, from the file or from the real environment. */
    protected function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
        $this->set[] = $key;
    }

    #[Test]
    public function a_fresh_artisan_reads_what_came_from_the_file_again_and_keeps_the_real_environment(): void
    {
        File::put($this->directory.'/.env', "DISSECT_PROBE_DATABASE=shop\nDISSECT_PROBE_HOST=mysql\n");
        $loaded = LoadedEnvironment::read($this->directory.'/.env');

        // DATABASE came from the file; HOST was set outside it, which wins.
        $this->setEnv('DISSECT_PROBE_DATABASE', 'shop');
        $this->setEnv('DISSECT_PROBE_HOST', '127.0.0.1');

        $this->assertSame(['DISSECT_PROBE_DATABASE' => false], $loaded->stale());

        $seen = Payloads::fresh([PHP_BINARY, $this->directory.'/artisan'], $this->directory, env: $loaded->stale())->get('schema');

        $this->assertSame(['database' => false, 'host' => '127.0.0.1'], $seen);
    }

    #[Test]
    public function without_a_file_nothing_is_left_out(): void
    {
        $this->assertSame([], LoadedEnvironment::read($this->directory.'/.env')->stale());
    }
}
