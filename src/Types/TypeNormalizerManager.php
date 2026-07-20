<?php

namespace KdrDev\Dissect\Types;

use Illuminate\Database\DatabaseManager;
use KdrDev\Dissect\Contracts\TypeNormalizer;
use Throwable;

/**
 * Resolves the right normalizer for a connection.
 *
 * Models may sit on different connections — and different drivers — within one
 * application, so resolution is per connection rather than global.
 */
class TypeNormalizerManager
{
    /** @var array<string, callable(): TypeNormalizer> */
    protected static array $custom = [];

    /** @var array<string, TypeNormalizer> */
    protected array $resolved = [];

    public function __construct(protected DatabaseManager $db) {}

    /**
     * Register support for a driver, or replace a built-in one.
     *
     * Static so it can be called from a host application's service provider
     * without having to resolve this class first.
     */
    public static function extend(string $driver, callable $factory): void
    {
        static::$custom[strtolower($driver)] = $factory;
    }

    /** Only used by tests, to stop registrations leaking between cases. */
    public static function flushCustom(): void
    {
        static::$custom = [];
    }

    public function for(?string $connection = null): TypeNormalizer
    {
        $driver = $this->driverFor($connection);

        return $this->resolved[$driver] ??= $this->make($driver);
    }

    protected function make(string $driver): TypeNormalizer
    {
        if (isset(static::$custom[$driver])) {
            return (static::$custom[$driver])();
        }

        return match ($driver) {
            'pgsql' => new PostgresTypeNormalizer,
            'mysql', 'mariadb' => new MySqlTypeNormalizer,
            'sqlite' => new SqliteTypeNormalizer,
            'sqlsrv' => new SqlServerTypeNormalizer,
            // An unknown driver still renders, just without semantic grouping.
            default => new GenericTypeNormalizer,
        };
    }

    protected function driverFor(?string $connection): string
    {
        try {
            return strtolower($this->db->connection($connection)->getDriverName());
        } catch (Throwable) {
            // A misconfigured or unreachable connection must not break the page;
            // the generic normalizer is always safe.
            return 'unknown';
        }
    }
}
