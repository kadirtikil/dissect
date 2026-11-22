<?php

namespace KdrDev\Dissect\Tests\Changes;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use KdrDev\Dissect\Changes\Baselines;
use KdrDev\Dissect\Changes\Snapshot;
use KdrDev\Dissect\Changes\UnreadableSnapshot;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BaselinesTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/dissect-baselines-'.bin2hex(random_bytes(4));
        config()->set('dissect.snapshot_path', $this->directory.'/.dissect/snapshot.json');
        config()->set('dissect.watch_path', $this->directory.'/storage/watch.json');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    protected function baselines(): Baselines
    {
        return $this->app->make(Baselines::class);
    }

    #[Test]
    public function watching_is_preferred_then_the_committed_snapshot_then_none(): void
    {
        $this->assertNull($this->baselines()->preferred());

        Artisan::call('dissect:snapshot');
        $this->assertSame('snapshot', $this->baselines()->preferred());

        Artisan::call('dissect:snapshot', ['--watch' => true]);
        $this->assertSame('watch', $this->baselines()->preferred());

        $this->baselines()->forget('watch');
        $this->assertSame('snapshot', $this->baselines()->preferred());
    }

    #[Test]
    public function snapshot_watch_starts_watching_without_touching_the_committed_one(): void
    {
        $code = Artisan::call('dissect:snapshot', ['--watch' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Watching from now: ', Artisan::output());
        $this->assertFileExists($this->directory.'/storage/watch.json');
        $this->assertFileDoesNotExist($this->directory.'/.dissect/snapshot.json');
        $this->assertSame(Snapshot::VERSION, $this->baselines()->read('watch')['version']);
    }

    #[Test]
    public function starting_again_restarts_the_clock_even_with_nothing_changed(): void
    {
        Artisan::call('dissect:snapshot', ['--watch' => true]);
        touch($this->directory.'/storage/watch.json', time() - 3600);
        clearstatcache();
        $before = $this->baselines()->takenAt('watch');

        Artisan::call('dissect:snapshot', ['--watch' => true]);

        $this->assertNotSame($before, $this->baselines()->takenAt('watch'));
    }

    #[Test]
    public function each_baseline_is_described_for_a_reader_choosing_one(): void
    {
        Artisan::call('dissect:snapshot', ['--watch' => true]);

        $described = $this->baselines()->describe();

        $this->assertSame(['watch', 'snapshot'], array_keys($described));
        $this->assertTrue($described['watch']['exists']);
        $this->assertSame($this->directory.'/storage/watch.json', $described['watch']['path']);
        $this->assertNotNull($described['watch']['taken_at']);
        $this->assertSame(['exists' => false, 'path' => $this->directory.'/.dissect/snapshot.json', 'taken_at' => null], $described['snapshot']);
    }

    #[Test]
    public function the_signal_moves_when_a_baseline_is_taken_or_dropped(): void
    {
        $none = $this->baselines()->signal();
        Artisan::call('dissect:snapshot', ['--watch' => true]);
        $watching = $this->baselines()->signal();
        $this->baselines()->forget('watch');

        $this->assertNotSame($none, $watching);
        $this->assertSame($none, $this->baselines()->signal());
    }

    #[Test]
    public function nothing_watched_says_how_to_start(): void
    {
        $this->expectException(UnreadableSnapshot::class);
        $this->expectExceptionMessage('Nothing is being watched. Start watching on the page, or with: php artisan dissect:snapshot --watch');

        $this->baselines()->read('watch');
    }

    #[Test]
    public function the_committed_snapshot_is_never_dropped_from_here(): void
    {
        Artisan::call('dissect:snapshot');

        try {
            $this->baselines()->forget('snapshot');
            $this->fail('The committed snapshot was dropped.');
        } catch (\RuntimeException) {
            $this->assertFileExists($this->directory.'/.dissect/snapshot.json');
        }
    }

    #[Test]
    public function diff_against_watch_compares_with_the_watch_baseline(): void
    {
        Artisan::call('dissect:snapshot', ['--watch' => true]);

        $this->assertSame(0, Artisan::call('dissect:diff', ['--against' => 'watch']));
        $this->assertStringContainsString('Nothing changed since '.$this->directory.'/storage/watch.json.', Artisan::output());

        // The default is still the committed one, which does not exist.
        $this->assertSame(1, Artisan::call('dissect:diff'));
        $this->assertStringContainsString('There is no snapshot at ', Artisan::output());

        $this->baselines()->forget('watch');
        $this->assertSame(1, Artisan::call('dissect:diff', ['--against' => 'watch']));
        $this->assertStringContainsString('Nothing is being watched.', Artisan::output());
    }
}
