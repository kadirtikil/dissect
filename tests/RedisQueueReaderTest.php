<?php

namespace KdrDev\Dissect\Tests;

use Illuminate\Queue\RedisQueue;
use KdrDev\Dissect\Queue\PayloadDecoder;
use KdrDev\Dissect\Queue\RedisQueueReader;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Redis reader against an in-memory stand-in for the connection.
 *
 * The suite has no Redis server, so this covers what the reader decides —
 * which queues exist, and how often it asks — rather than the wire format.
 */
class RedisQueueReaderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    #[Test]
    public function it_walks_the_keyspace_once_for_a_whole_snapshot(): void
    {
        $redis = new FakeRedis(['queues:default', 'queues:mail', 'queues:mail:delayed']);
        $reader = $this->reader($redis);

        $reader->queues();
        $reader->waiting(50);
        $reader->reserved(50);
        $reader->delayed(50);

        $this->assertSame(1, $redis->keysCalls);
    }

    #[Test]
    public function it_keeps_a_queue_name_that_contains_a_colon_whole(): void
    {
        $reader = $this->reader(new FakeRedis([
            'laravel_database_queues:emails:high',
            'laravel_database_queues:emails:high:delayed',
            'laravel_database_queues:emails:high:reserved',
            'laravel_database_queues:{reports}:notify',
            'laravel_database_queues:default',
        ]));

        $this->assertSame(
            ['default', 'emails:high', 'reports'],
            array_column($reader->queues(), 'name'),
        );
    }

    protected function reader(FakeRedis $redis): RedisQueueReader
    {
        $queue = Mockery::mock(RedisQueue::class);
        $queue->shouldReceive('getConnection')->andReturn($redis);
        $queue->shouldReceive('getQueue')->andReturnUsing(fn (string $name) => 'queues:'.$name);

        return new RedisQueueReader($queue, new PayloadDecoder);
    }
}

/** Answers the handful of commands the reader sends, from a fixed key list. */
class FakeRedis
{
    public int $keysCalls = 0;

    /** @param  array<int, string>  $keys */
    public function __construct(protected array $keys) {}

    /** @return array<int, string> */
    public function keys(string $pattern): array
    {
        $this->keysCalls++;

        return $this->keys;
    }

    public function llen(string $key): int
    {
        return 0;
    }

    public function zcard(string $key): int
    {
        return 0;
    }

    /** @return array<int, string> */
    public function lrange(string $key, int $start, int $stop): array
    {
        return [];
    }

    /** @return array<int, string> */
    public function eval(string $script, int $keys, mixed ...$arguments): array
    {
        return [];
    }
}
