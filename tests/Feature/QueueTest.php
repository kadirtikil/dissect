<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KdrDev\Dissect\Queue\QueueSnapshot;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The live half of the queue surface.
 *
 * Unlike every other test here, this one is about runtime state rather than
 * source: rows are put on a real queue table and read back. The `database`
 * driver is what the suite can exercise without a service to run — Redis is
 * covered by the same contract and by nothing else, which the architecture
 * notes say out loud.
 */
class QueueTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Testbench defaults to `sync`, which is the one driver that never has
        // anything to look at.
        $app['config']->set('queue.default', 'database');
    }

    #[Test]
    public function it_reads_jobs_waiting_to_be_picked_up(): void
    {
        $this->push('Workbench\App\Jobs\PublishPost', queue: 'publishing');
        $this->push('Workbench\App\Jobs\PruneComments');

        $waiting = $this->snapshot()['now']['waiting'];

        $this->assertSame(2, $waiting['total']);
        $this->assertFalse($waiting['truncated']);
        $this->assertSame(
            ['PruneComments', 'PublishPost'],
            collect($waiting['rows'])->pluck('name')->sort()->values()->all(),
        );
    }

    #[Test]
    public function it_tells_the_three_tenses_apart(): void
    {
        $this->push('Workbench\App\Jobs\PublishPost');
        $this->push('Workbench\App\Jobs\PruneComments', reservedAt: time() - 30);
        $this->push('Workbench\App\Jobs\RebuildSearchIndex', availableAt: time() + 3600);

        $snapshot = $this->snapshot();

        $this->assertSame(['PublishPost'], $this->names($snapshot['now']['waiting']));
        // Taken by a worker and not yet finished — still "now", but a different
        // thing to know.
        $this->assertSame(['PruneComments'], $this->names($snapshot['now']['reserved']));
        // Held until a time that has not arrived: the "going to be" tense.
        $this->assertSame(['RebuildSearchIndex'], $this->names($snapshot['next']['delayed']));
    }

    #[Test]
    public function it_names_the_job_class_the_jobs_surface_lists(): void
    {
        // The join: `job` is the same id jobs.json uses, so a row on a live
        // queue and a row in the job list address the same class.
        $this->push('Workbench\App\Jobs\PublishPost');

        $row = $this->snapshot()['now']['waiting']['rows'][0];

        $this->assertSame('Workbench\App\Jobs\PublishPost', $row['job']);
        $this->assertSame('PublishPost', $row['name']);
    }

    #[Test]
    public function it_never_unserialises_the_command_on_a_payload(): void
    {
        // The whole safety story. Restoring the command would construct the
        // application's own job, run its __wakeup, and on a SerializesModels
        // job go to the database for every model it carries.
        ExplodingCommand::$woken = false;

        $this->push('Workbench\App\Jobs\PublishPost', command: new ExplodingCommand);

        $this->snapshot();

        $this->assertFalse(ExplodingCommand::$woken, 'The serialised command was unserialised.');
    }

    #[Test]
    public function it_never_reports_the_payload_itself(): void
    {
        // A queue payload holds whatever a constructor was handed. The envelope
        // is safe to describe; the command is somebody's data.
        $this->push('Workbench\App\Jobs\PublishPost');

        $row = $this->snapshot()['now']['waiting']['rows'][0];

        $this->assertArrayNotHasKey('payload', $row);
        $this->assertArrayNotHasKey('command', $row);
    }

    #[Test]
    public function it_counts_the_whole_queue_but_lists_only_a_page_of_it(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->push('Workbench\App\Jobs\PublishPost');
        }

        $waiting = $this->snapshot(limit: 2)['now']['waiting'];

        // A queue is a place where the number is enormous and the first page is
        // what anybody reads.
        $this->assertSame(5, $waiting['total']);
        $this->assertCount(2, $waiting['rows']);
        $this->assertTrue($waiting['truncated']);
    }

    #[Test]
    public function it_reports_the_depth_of_each_queue(): void
    {
        $this->push('Workbench\App\Jobs\PublishPost', queue: 'publishing');
        $this->push('Workbench\App\Jobs\PruneComments', queue: 'maintenance');
        $this->push('Workbench\App\Jobs\PruneComments', queue: 'maintenance', availableAt: time() + 600);

        $queues = collect($this->snapshot()['queues'])->keyBy('name');

        $this->assertSame(1, $queues['publishing']['waiting']);
        $this->assertSame(1, $queues['maintenance']['waiting']);
        $this->assertSame(1, $queues['maintenance']['delayed']);
        $this->assertSame(0, $queues['maintenance']['reserved']);
    }

    #[Test]
    public function it_reads_what_failed(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'publishing',
            'payload' => $this->payload('Workbench\App\Jobs\PublishPost'),
            'exception' => "RuntimeException: the printer is on fire\n#0 /app/vendor/…",
            'failed_at' => now(),
        ]);

        $failed = $this->snapshot()['past']['failed'];

        $this->assertSame(1, $failed['total']);
        $this->assertSame('PublishPost', $failed['rows'][0]['name']);
        // The first line is the exception and its message; the rest is a
        // hundred frames of vendor code that belong in the log.
        $this->assertSame('RuntimeException: the printer is on fire', $failed['rows'][0]['exception']);
    }

    #[Test]
    public function it_reports_batches_as_the_other_half_of_the_past(): void
    {
        DB::table('job_batches')->insert([
            'id' => 'batch-uuid',
            'name' => 'Publish everything',
            'total_jobs' => 10,
            'pending_jobs' => 2,
            'failed_jobs' => 1,
            'failed_job_ids' => '[]',
            // Serialised, the way the framework writes it — an empty string
            // here makes the repository's own unserialize() throw.
            'options' => serialize([]),
            'created_at' => time(),
            'finished_at' => null,
        ]);

        $batch = $this->snapshot()['past']['batches'][0];

        $this->assertSame('Publish everything', $batch['name']);
        $this->assertSame(10, $batch['total']);
        $this->assertSame(2, $batch['pending']);
        $this->assertSame(1, $batch['failed']);
    }

    #[Test]
    public function it_says_why_a_history_is_empty_when_it_could_not_be_read(): void
    {
        // "No job has failed" and "the failed_jobs table is not there" are
        // different answers, and showing the first for the second is the same
        // lie the surface refuses to tell about an unreadable driver.
        config(['queue.failed.table' => 'a_table_that_is_not_there']);

        $failed = $this->snapshot()['past']['failed'];

        $this->assertSame(0, $failed['total']);
        $this->assertStringContainsString('could not be read', (string) $failed['unreadable']);
    }

    #[Test]
    public function it_reports_a_readable_but_empty_history_as_exactly_that(): void
    {
        $this->assertNull($this->snapshot()['past']['failed']['unreadable']);
    }

    #[Test]
    public function it_says_that_a_job_which_succeeded_is_recorded_nowhere(): void
    {
        // The honest half. Laravel keeps no trace of a job that worked, so an
        // empty history means "nothing failed", not "nothing ran" — and the
        // payload says so rather than leaving the surface to imply it.
        $this->assertFalse($this->snapshot()['records_completions']);
    }

    #[Test]
    public function it_refuses_a_driver_it_cannot_enumerate_with_a_reason(): void
    {
        config(['queue.default' => 'sqs']);

        $snapshot = $this->snapshot();

        $this->assertFalse($snapshot['readable']);
        // An empty queue and an unreadable one are different answers, and
        // showing the first for the second would be a lie.
        $this->assertStringContainsString('without receiving it', $snapshot['refusal']);
        $this->assertSame([], $snapshot['queues']);
    }

    #[Test]
    public function it_still_reads_the_history_of_an_unreadable_driver(): void
    {
        // Failures are stored by the application, not by the queue driver, so
        // an SQS application still knows what went wrong.
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'sqs',
            'queue' => 'default',
            'payload' => $this->payload('Workbench\App\Jobs\PublishPost'),
            'exception' => 'RuntimeException: nope',
            'failed_at' => now(),
        ]);

        config(['queue.default' => 'sqs']);

        $this->assertSame(1, $this->snapshot()['past']['failed']['total']);
    }

    #[Test]
    public function it_falls_back_to_the_default_for_a_connection_that_is_not_configured(): void
    {
        // A name that is not configured would throw on the first read; the
        // question that was probably meant is the default one.
        $this->assertSame('database', $this->snapshot('nonsense')['connection']);
    }

    #[Test]
    public function it_serves_the_queue_without_letting_anything_cache_it(): void
    {
        $response = $this->get('dissect/queue.json');

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonStructure(['connection', 'driver', 'readable', 'queues', 'now', 'next', 'past']);
    }

    #[Test]
    public function it_reads_the_queue_again_on_every_request(): void
    {
        // The one endpoint that is never cached: a fingerprint cannot describe
        // runtime state, and a queue that looked the same for an hour because a
        // cache said so would be worse than no surface at all.
        $this->assertSame(0, $this->get('dissect/queue.json')->json('now.waiting.total'));

        $this->push('Workbench\App\Jobs\PublishPost');

        $this->assertSame(1, $this->get('dissect/queue.json')->json('now.waiting.total'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(?string $connection = null, int $limit = 50): array
    {
        return $this->app->make(QueueSnapshot::class)->take($connection, $limit);
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<int, string>
     */
    protected function names(array $section): array
    {
        return array_column($section['rows'], 'name');
    }

    /** A row on the queue table, written the way Laravel writes one. */
    protected function push(
        string $class,
        string $queue = 'default',
        ?int $reservedAt = null,
        ?int $availableAt = null,
        ?object $command = null,
    ): void {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => $this->payload($class, $command),
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt ?? time() - 5,
            'created_at' => time() - 10,
        ]);
    }

    protected function payload(string $class, ?object $command = null): string
    {
        return (string) json_encode([
            'uuid' => (string) Str::uuid(),
            // Already unwrapped by the framework: for a queued listener or
            // mailable, commandName would only ever say CallQueuedListener.
            'displayName' => $class,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => 3,
            'attempts' => 0,
            'pushedAt' => (string) microtime(true),
            'data' => [
                'commandName' => $class,
                'command' => serialize($command ?? new ExplodingCommand),
            ],
        ]);
    }
}

/**
 * Proves the command is never restored.
 *
 * Unserialising this sets the flag; nothing in the reader should ever be able
 * to, because the JSON envelope answers every question the surface asks.
 */
class ExplodingCommand
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}
