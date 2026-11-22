<?php

namespace KdrDev\Dissect\Tests\Mcp;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use KdrDev\Dissect\Changes\Snapshot;
use KdrDev\Dissect\Mcp\DissectServer;
use KdrDev\Dissect\Mcp\Payloads;
use KdrDev\Dissect\Mcp\Tools\Changes;
use KdrDev\Dissect\Support\Surfaces;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ChangesToolTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Payloads::class, Payloads::inProcess($this->app->make(Surfaces::class)));

        $this->directory = sys_get_temp_dir().'/dissect-mcp-changes-'.bin2hex(random_bytes(4));
        config()->set('dissect.snapshot_path', $this->directory.'/.dissect/snapshot.json');
        config()->set('dissect.watch_path', $this->directory.'/storage/watch.json');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** A committed snapshot from before the store route and Post's tags. */
    protected function commitOlderSnapshot(): void
    {
        $snapshot = $this->app->make(Snapshot::class)->take();
        unset($snapshot['routes']['POST:api/posts'], $snapshot['models']['Workbench\\App\\Models\\Post']['relations']['tags']);

        (new Filesystem)->ensureDirectoryExists($this->directory.'/.dissect');
        file_put_contents($this->directory.'/.dissect/snapshot.json', Snapshot::encode($snapshot));
    }

    #[Test]
    public function it_lists_what_changed_since_the_committed_snapshot(): void
    {
        $this->commitOlderSnapshot();

        DissectServer::tool(Changes::class)
            ->assertOk()
            ->assertSee([
                '# Changes since '.$this->directory.'/.dissect/snapshot.json',
                '2 changes: 2 additive.',
                '- additive `+ relation tags (MorphToMany Tag)`',
                '- additive `+ route POST api/posts`',
            ]);
    }

    #[Test]
    public function watching_is_what_it_compares_against_when_the_user_is_watching(): void
    {
        $this->commitOlderSnapshot();
        Artisan::call('dissect:snapshot', ['--watch' => true]);

        DissectServer::tool(Changes::class)->assertOk()->assertSee(['# Changes since watching started', 'Nothing changed.']);
        DissectServer::tool(Changes::class, ['against' => 'snapshot'])->assertOk()->assertSee('+ route POST api/posts');
    }

    #[Test]
    public function with_nothing_to_compare_against_it_says_who_can_take_a_baseline(): void
    {
        DissectServer::tool(Changes::class)
            ->assertOk()
            ->assertSee(['There is nothing to compare against yet', 'php artisan dissect:snapshot --watch']);
    }

    #[Test]
    public function a_baseline_it_cannot_read_is_said_in_words(): void
    {
        (new Filesystem)->ensureDirectoryExists($this->directory.'/.dissect');
        file_put_contents($this->directory.'/.dissect/snapshot.json', '{"version": 99}');

        DissectServer::tool(Changes::class)->assertOk()->assertSee('is in snapshot format 99');
    }
}
