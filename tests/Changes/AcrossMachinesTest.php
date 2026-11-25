<?php

namespace KdrDev\Dissect\Tests\Changes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use KdrDev\Dissect\Changes\Report;
use KdrDev\Dissect\Changes\Snapshot;
use KdrDev\Dissect\SchemaExporter;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A snapshot committed from a laptop and compared in CI: same code, another
 * machine. Nothing the machine decides should read as a change.
 */
class AcrossMachinesTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $node */
    protected function models(array ...$nodes): array
    {
        return Snapshot::of(['schema' => ['nodes' => $nodes, 'edges' => []]]);
    }

    /** @param  array<int, array<string, mixed>>  $columns */
    protected function invoice(string $driver, array $columns, array $extra = []): array
    {
        return [
            'id' => 'App\\Models\\Invoice',
            'table' => 'invoices',
            'connection' => null,
            'driver' => $driver,
            'columns' => array_map(fn (array $column) => $column + ['nullable' => false, 'unique' => false, 'virtual' => false], $columns),
            ...$extra,
        ];
    }

    /** @return array<int, string> */
    protected function facts(array $before, array $after): array
    {
        $changes = Report::between($before, $after)['changes'];

        return array_merge(...array_map(fn (array $change) => array_map(fn (array $fact) => implode('.', $fact['path']), $change['facts']), $changes), ...[[]]);
    }

    #[Test]
    public function the_same_columns_on_another_database_are_no_change(): void
    {
        $mysql = $this->models($this->invoice('mysql', [
            ['name' => 'id', 'type' => 'bigint unsigned', 'kind' => 'integer'],
            ['name' => 'number', 'type' => 'varchar(255)', 'kind' => 'string'],
        ]));
        $sqlite = $this->models($this->invoice('sqlite', [
            ['name' => 'id', 'type' => 'integer', 'kind' => 'integer'],
            ['name' => 'number', 'type' => 'varchar', 'kind' => 'string'],
        ]));

        $this->assertSame([], Report::between($mysql, $sqlite)['changes']);
    }

    #[Test]
    public function across_databases_a_column_that_changed_kind_still_shows(): void
    {
        $mysql = $this->models($this->invoice('mysql', [['name' => 'number', 'type' => 'varchar(255)', 'kind' => 'string']]));
        $sqlite = $this->models($this->invoice('sqlite', [['name' => 'number', 'type' => 'integer', 'kind' => 'integer']]));

        $this->assertSame(['columns.number.kind'], $this->facts($mysql, $sqlite));
    }

    #[Test]
    public function on_one_database_the_native_type_is_compared(): void
    {
        $before = $this->models($this->invoice('mysql', [['name' => 'id', 'type' => 'int unsigned', 'kind' => 'integer']]));
        $after = $this->models($this->invoice('mysql', [['name' => 'id', 'type' => 'bigint unsigned', 'kind' => 'integer']]));

        $this->assertSame(['columns.id.type'], $this->facts($before, $after));
    }

    #[Test]
    public function a_model_whose_columns_could_not_be_read_has_none_removed(): void
    {
        $read = $this->models($this->invoice('mysql', [['name' => 'id', 'type' => 'bigint unsigned', 'kind' => 'integer']]));
        $unread = $this->models($this->invoice('sqlite', [], ['unreadable' => 'The table invoices is not in the database.']));

        $this->assertTrue($unread['models']['App\\Models\\Invoice']['unreadable']);
        $this->assertSame([], Report::between($read, $unread)['changes']);
        $this->assertSame([], Report::between($unread, $read)['changes']);

        // What can still be read is still compared.
        $renamed = $this->models($this->invoice('sqlite', [], ['table' => 'bills', 'unreadable' => 'The table bills is not in the database.']));
        $this->assertSame(['table'], $this->facts($read, $renamed));
    }

    #[Test]
    public function the_export_says_which_driver_and_names_only_a_connection_the_model_sets(): void
    {
        $nodes = collect($this->app->make(SchemaExporter::class)->export()['nodes'])->keyBy('id');
        $post = $nodes['Workbench\\App\\Models\\Post'];

        $this->assertNull($post['connection']);
        $this->assertSame('sqlite', $post['driver']);
        $this->assertArrayNotHasKey('unreadable', $post);
    }

    #[Test]
    public function the_export_flags_a_model_whose_table_or_connection_is_not_there(): void
    {
        $directory = sys_get_temp_dir().'/dissect-unread-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($directory);
        File::put($directory.'/Ghost.php', "<?php\nnamespace DissectUnread;\nclass Ghost extends \\Illuminate\\Database\\Eloquent\\Model { protected \$table = 'ghosts'; }\n");
        File::put($directory.'/Elsewhere.php', "<?php\nnamespace DissectUnread;\nclass Elsewhere extends \\Illuminate\\Database\\Eloquent\\Model { protected \$connection = 'warehouse'; }\n");
        require_once $directory.'/Ghost.php';
        require_once $directory.'/Elsewhere.php';

        try {
            config()->set('dissect.models.paths', [$directory]);
            $this->app->forgetInstance(SchemaExporter::class);
            $nodes = collect($this->app->make(SchemaExporter::class)->export()['nodes'])->keyBy('id');
        } finally {
            File::deleteDirectory($directory);
        }

        $this->assertSame('The table ghosts is not in the database.', $nodes['DissectUnread\\Ghost']['unreadable']);
        $this->assertSame('warehouse', $nodes['DissectUnread\\Elsewhere']['connection']);
        $this->assertStringContainsString('warehouse', $nodes['DissectUnread\\Elsewhere']['unreadable']);
    }
}
