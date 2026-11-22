<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use KdrDev\Dissect\Changes\Snapshot;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * /changes.json: what changed since a baseline, for the page; and starting
 * and stopping watching.
 */
class ChangesTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/dissect-changes-'.bin2hex(random_bytes(4));
        config()->set('dissect.snapshot_path', $this->directory.'/.dissect/snapshot.json');
        config()->set('dissect.watch_path', $this->directory.'/storage/watch.json');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** The committed snapshot, as it would have been before Post's store route existed. */
    protected function commitSnapshotWithoutStore(): void
    {
        $snapshot = $this->app->make(Snapshot::class)->take();
        unset($snapshot['routes']['POST:api/posts']);

        (new Filesystem)->ensureDirectoryExists($this->directory.'/.dissect');
        file_put_contents($this->directory.'/.dissect/snapshot.json', Snapshot::encode($snapshot));
    }

    #[Test]
    public function with_no_baseline_there_is_nothing_to_compare_against(): void
    {
        $this->getJson('/dissect/changes.json')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('against', null)
            ->assertJsonPath('changes', [])
            ->assertJsonPath('problem', null)
            ->assertJsonPath('baselines.watch.exists', false)
            ->assertJsonPath('baselines.snapshot.exists', false);
    }

    #[Test]
    public function it_compares_with_the_committed_snapshot(): void
    {
        $this->commitSnapshotWithoutStore();

        $this->getJson('/dissect/changes.json')
            ->assertOk()
            ->assertJsonPath('against', 'snapshot')
            ->assertJsonPath('summary', ['breaking' => 0, 'risky' => 0, 'additive' => 1])
            ->assertJsonPath('changes.0.text', '+ route POST api/posts')
            ->assertJsonPath('changes.0.severity', 'additive');
    }

    #[Test]
    public function watching_is_preferred_and_against_picks_the_other(): void
    {
        $this->commitSnapshotWithoutStore();

        $this->postJson('/dissect/changes/watch')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('baselines.watch.exists', true);

        $this->assertFileExists($this->directory.'/storage/watch.json');

        $this->getJson('/dissect/changes.json')->assertJsonPath('against', 'watch')->assertJsonPath('changes', []);
        $this->getJson('/dissect/changes.json?against=snapshot')->assertJsonPath('against', 'snapshot')->assertJsonCount(1, 'changes');
        $this->getJson('/dissect/changes.json?against=nonsense')->assertJsonPath('against', 'watch');
    }

    #[Test]
    public function stopping_drops_the_watch_and_leaves_the_committed_snapshot(): void
    {
        $this->commitSnapshotWithoutStore();
        $this->postJson('/dissect/changes/watch')->assertOk();

        $this->deleteJson('/dissect/changes/watch')
            ->assertOk()
            ->assertJsonPath('baselines.watch.exists', false)
            ->assertJsonPath('baselines.snapshot.exists', true);

        $this->assertFileDoesNotExist($this->directory.'/storage/watch.json');
        $this->getJson('/dissect/changes.json')->assertJsonPath('against', 'snapshot');
    }

    #[Test]
    public function a_snapshot_it_cannot_read_is_a_problem_in_words_not_an_error(): void
    {
        (new Filesystem)->ensureDirectoryExists($this->directory.'/.dissect');
        file_put_contents($this->directory.'/.dissect/snapshot.json', '{"version": 99}');

        $this->getJson('/dissect/changes.json')
            ->assertOk()
            ->assertJsonPath('against', 'snapshot')
            ->assertJsonPath('changes', [])
            ->assertJsonPath('problem', fn (string $problem) => str_contains($problem, 'is in snapshot format 99'));
    }

    #[Test]
    public function the_fingerprint_moves_when_watching_starts_and_stops(): void
    {
        $fingerprint = fn () => $this->getJson('/dissect/fingerprint?changes=1')->assertOk()->json('changes');

        $before = $fingerprint();
        $this->assertSame($before, $fingerprint(), 'stable while nothing changes');

        $this->postJson('/dissect/changes/watch');
        $watching = $fingerprint();
        $this->deleteJson('/dissect/changes/watch');

        $this->assertNotSame($before, $watching);
        $this->assertSame($before, $fingerprint());
        $this->assertSame($before, $this->getJson('/dissect/changes.json')->json('fingerprint'));
    }

    #[Test]
    public function the_fingerprint_is_only_computed_when_asked_for(): void
    {
        $this->getJson('/dissect/fingerprint')->assertOk()->assertJsonMissingPath('changes');
    }
}
