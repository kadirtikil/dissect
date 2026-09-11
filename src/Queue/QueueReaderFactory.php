<?php

namespace KdrDev\Dissect\Queue;

use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use KdrDev\Dissect\Queue\Contracts\QueueReader;
use Throwable;

/**
 * Picks the reader for a queue connection, or explains why there isn't one.
 *
 * Two of Laravel's drivers can be enumerated and the rest cannot, and that is a
 * fact about the driver rather than a gap in this package — so the refusal is
 * reported with a reason, in the same spirit as `kind: unknown` on a column and
 * `confidence: unknown` on a response. A surface that silently showed an empty
 * queue for SQS would be lying about an empty queue.
 */
class QueueReaderFactory
{
    /**
     * Why each driver this does not read cannot be read.
     *
     * Written out per driver rather than as one "unsupported" message: "SQS
     * cannot list without consuming" and "sync never queues anything" are
     * different facts, and only one of them is worth trying to work around.
     */
    protected const REFUSALS = [
        'database:missing-table' => 'The queue table does not exist yet. `php artisan make:queue-table` and `php artisan migrate` create it.',
        'sqs' => 'SQS can report an approximate depth, but a message cannot be listed without receiving it — which would take it off the queue.',
        'sync' => 'The sync driver runs jobs the moment they are dispatched, so nothing is ever queued.',
        'null' => 'The null driver discards every job it is given.',
        'beanstalkd' => 'Beanstalkd is not read by dissect. Its jobs are visible through beanstalkd’s own tooling.',
    ];

    public function __construct(
        protected QueueManager $queues,
        protected DatabaseManager $database,
        protected PayloadDecoder $payloads,
    ) {}

    /** The reader for this connection, or null when there cannot be one. */
    public function make(string $connection): ?QueueReader
    {
        return match ($this->driver($connection)) {
            'database' => $this->database($connection),
            'redis' => $this->redis($connection),
            default => null,
        };
    }

    /** In words, for the surface to show where the rows would have been. */
    public function refusal(string $connection): string
    {
        $driver = $this->driver($connection);

        // A configured driver whose storage is not there yet is a different
        // problem from a driver that cannot be read at all, and it is the one
        // with something the reader can do about it.
        if ($driver === 'database' && ! $this->tableExists($connection)) {
            return self::REFUSALS['database:missing-table'];
        }

        return self::REFUSALS[$driver]
            ?? "The {$driver} driver is not one dissect knows how to read.";
    }

    /** Whether the configured queue table is actually there. */
    protected function tableExists(string $connection): bool
    {
        try {
            return $this->database
                ->connection(config("queue.connections.{$connection}.connection"))
                ->getSchemaBuilder()
                ->hasTable((string) config("queue.connections.{$connection}.table", 'jobs'));
        } catch (Throwable) {
            // A database that is down cannot be asked. Reporting the queue as
            // unreadable is the truth either way.
            return false;
        }
    }

    public function driver(string $connection): string
    {
        return (string) config("queue.connections.{$connection}.driver", 'null');
    }

    protected function database(string $connection): ?QueueReader
    {
        // Checked before the reader is built rather than caught on the first
        // query: half a snapshot, with counts that silently came back empty, is
        // worse than a refusal that names the missing table.
        if (! $this->tableExists($connection)) {
            return null;
        }

        try {
            return new DatabaseQueueReader(
                // The queue's own database connection, which is not necessarily
                // the default one — a queue is often deliberately kept off the
                // connection the application reads from.
                $this->database->connection(config("queue.connections.{$connection}.connection")),
                $this->payloads,
                (string) config("queue.connections.{$connection}.table", 'jobs'),
            );
        } catch (Throwable) {
            return null;
        }
    }

    protected function redis(string $connection): ?QueueReader
    {
        try {
            $queue = $this->queues->connection($connection);

            if (! $queue instanceof RedisQueue) {
                return null;
            }

            // A clustered connection stores its queues under hash-tagged keys,
            // and the method that builds those names is not public. Reading it
            // against the untagged names would quietly report an empty queue,
            // which is the one wrong answer worth refusing to give.
            if (str_contains($queue->getConnection()::class, 'Cluster')) {
                return null;
            }

            return new RedisQueueReader(
                $queue,
                $this->payloads,
                (string) config("queue.connections.{$connection}.queue", 'default'),
            );
        } catch (Throwable) {
            return null;
        }
    }
}
