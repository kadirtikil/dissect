<?php

namespace KdrDev\Dissect;

use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * A signal that changes only when migrations have actually been applied.
 *
 * The graph reads relations from the model files but columns from the live
 * database, so the two halves have different change signals. Writing or editing
 * a migration must *not* move this: the columns it describes do not exist yet,
 * and re-exporting then would show a schema nobody has.
 *
 * Laravel inserts a row into the migrations table only after a migration's
 * `up()` returns, inside the same run — so the contents of that table are
 * exactly "what has run successfully". A migration that threw half way leaves
 * no row, and therefore no new signal.
 *
 * Read from the default connection only. Migrations for other connections are
 * still recorded there unless the host has gone out of its way to split them,
 * and a per-connection sweep would cost a query per connection on every poll.
 */
class MigrationState
{
    public function __construct(protected DatabaseManager $db) {}

    /**
     * Opaque token: equal tokens mean no migration has run since.
     *
     * Never throws. A database that is down or not yet migrated is a normal
     * state for this tool — it degrades to "no migrations" and the schema
     * export falls back to whatever it can read.
     */
    public function signal(): string
    {
        try {
            $connection = $this->db->connection();
            $table = $this->table();

            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                return 'none';
            }

            $applied = $connection->table($table)->count();

            // The id alone would miss a rollback, the count alone would miss a
            // rollback followed by a different migration. Together they move on
            // either, for two indexed queries.
            $latest = $connection->table($table)->max('id');

            return $applied.':'.($latest ?? 0);
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    /** Laravel 11 allows the migrations config to be an array of options. */
    protected function table(): string
    {
        $configured = config('database.migrations', 'migrations');

        if (is_array($configured)) {
            return (string) ($configured['table'] ?? 'migrations');
        }

        return (string) $configured;
    }
}
