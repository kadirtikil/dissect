<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Puts something on the fixture app's queue, so the live surface has a queue to
 * show.
 *
 * Rows are written straight to the table rather than dispatched, for the same
 * reason the rest of the fixture is hand-built: dispatching `PublishPost` needs
 * a `Post` row to carry, and this command's job is to produce a queue, not to
 * arrange the database into a state where one can be produced.
 *
 * It also covers the shapes worth looking at — waiting, reserved, delayed, a
 * failure, and a batch half-finished — which are exactly the ones an idle
 * development queue never has.
 */
class SeedQueueCommand extends Command
{
    protected $signature = 'dissect:seed-queue {--clear : Empty the queue tables instead}';

    protected $description = 'Fill the fixture queue so the live surface has something to read';

    public function handle(): int
    {
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        DB::table('job_batches')->delete();

        if ($this->option('clear')) {
            $this->info('Queue tables emptied.');

            return self::SUCCESS;
        }

        $this->waiting();
        $this->reserved();
        $this->delayed();
        $this->failed();
        $this->batches();

        $this->info('Queued: 4 waiting, 1 reserved, 2 delayed, 2 failed, 2 batches.');
        $this->line('Open /dissect#/queue to see them.');

        return self::SUCCESS;
    }

    protected function waiting(): void
    {
        $this->push('Workbench\App\Jobs\PublishPost', 'publishing', createdAt: time() - 240);
        $this->push('Workbench\App\Jobs\PublishPost', 'publishing', createdAt: time() - 90);
        $this->push('Workbench\App\Mail\WeeklyDigest', 'mail', createdAt: time() - 60);
        $this->push('Workbench\App\Listeners\NotifyFollowers', 'listeners', createdAt: time() - 20);
    }

    /** Taken by a worker four minutes ago and still going — the stuck-job shape. */
    protected function reserved(): void
    {
        $this->push(
            'Workbench\App\Jobs\PublishPost',
            'publishing',
            attempts: 1,
            reservedAt: time() - 240,
            createdAt: time() - 300,
        );
    }

    protected function delayed(): void
    {
        $this->push('Workbench\App\Jobs\PruneComments', 'maintenance', availableAt: time() + 900);
        $this->push('Workbench\App\Jobs\SyncAuthorProfile', 'profiles', availableAt: time() + 5400);
    }

    protected function failed(): void
    {
        $failures = [
            ['Workbench\App\Jobs\PublishPost', 'publishing', 'RuntimeException: the search index refused the document', 1800],
            ['Workbench\App\Mail\WeeklyDigest', 'mail', 'Symfony\Component\Mailer\Exception\TransportException: Connection refused', 7200],
        ];

        foreach ($failures as [$class, $queue, $exception, $ago]) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => $queue,
                // A job only reaches this table once its attempts are spent.
                'payload' => $this->payload($class, attempts: 3),
                // A real one is a hundred frames long; only the first line is
                // ever shown, and this proves it.
                'exception' => $exception."\n#0 /app/vendor/laravel/framework/src/…\n#1 /app/vendor/…",
                'failed_at' => now()->subSeconds($ago),
            ]);
        }
    }

    protected function batches(): void
    {
        DB::table('job_batches')->insert([
            [
                'id' => (string) Str::uuid(),
                'name' => 'Republish every post',
                'total_jobs' => 120,
                'pending_jobs' => 34,
                'failed_jobs' => 2,
                'failed_job_ids' => '[]',
                'options' => serialize([]),
                'created_at' => time() - 600,
                'finished_at' => null,
            ],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Nightly digest',
                'total_jobs' => 40,
                'pending_jobs' => 0,
                'failed_jobs' => 0,
                'failed_job_ids' => '[]',
                'options' => serialize([]),
                'created_at' => time() - 86400,
                'finished_at' => time() - 85800,
            ],
        ]);
    }

    protected function push(
        string $class,
        string $queue,
        int $attempts = 0,
        ?int $reservedAt = null,
        ?int $availableAt = null,
        ?int $createdAt = null,
    ): void {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => $this->payload($class),
            'attempts' => $attempts,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt ?? time() - 5,
            'created_at' => $createdAt ?? time() - 30,
        ]);
    }

    /** The envelope Laravel writes, minus a command worth serialising. */
    protected function payload(string $class, int $attempts = 0): string
    {
        return (string) json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => $class,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => 3,
            'attempts' => $attempts,
            'pushedAt' => (string) microtime(true),
            'data' => ['commandName' => $class, 'command' => 'N;'],
        ]);
    }
}
