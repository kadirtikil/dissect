<?php

namespace KdrDev\Dissect\Tests\Feature;

use KdrDev\Dissect\SchemaExporter;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Models in more than one place: a Models folder per domain, as a modular
 * application keeps them.
 */
class ModelPathsTest extends TestCase
{
    protected static string $root;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::$root = sys_get_temp_dir().'/dissect-model-paths-'.uniqid();

        $models = [
            // Two domains, one class name: the case one directory and one
            // namespace could not describe.
            'Domain/Billing/Models/Invoice.php' => 'DissectDomains\Billing\Models',
            'Domain/Sales/Models/Invoice.php' => 'DissectDomains\Sales\Models',
            'Domain/Sales/Models/Quote.php' => 'DissectDomains\Sales\Models',
            // A folder that does not spell its namespace.
            'legacy/stuff/Ledger.php' => 'DissectDomains\Accounting',
        ];

        foreach ($models as $file => $namespace) {
            $path = static::$root.'/'.$file;
            @mkdir(dirname($path), 0755, true);
            $class = basename($file, '.php');
            file_put_contents($path, "<?php\n\nnamespace {$namespace};\n\nclass {$class} extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
            require_once $path;
        }

        // A domain with no models yet.
        @mkdir(static::$root.'/Domain/Shipping', 0755, true);
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf '.escapeshellarg(static::$root));

        parent::tearDownAfterClass();
    }

    /** @return array<int, string> */
    protected function models(array $paths): array
    {
        config()->set('dissect.models.paths', $paths);
        $this->app->forgetInstance(SchemaExporter::class);

        $models = $this->app->make(SchemaExporter::class)->discoverModels();
        sort($models);

        return $models;
    }

    #[Test]
    public function a_star_covers_every_domains_models_folder(): void
    {
        $this->assertSame([
            'DissectDomains\Billing\Models\Invoice',
            'DissectDomains\Sales\Models\Invoice',
            'DissectDomains\Sales\Models\Quote',
        ], $this->models([static::$root.'/Domain/*/Models']));
    }

    #[Test]
    public function several_paths_are_read_together(): void
    {
        $models = $this->models([
            static::$root.'/Domain/*/Models',
            __DIR__.'/../../workbench/app/Models',
        ]);

        $this->assertContains('DissectDomains\Sales\Models\Quote', $models);
        $this->assertContains('Workbench\App\Models\Post', $models);
    }

    #[Test]
    public function the_class_name_comes_from_the_file_not_the_folder(): void
    {
        $this->assertSame(['DissectDomains\Accounting\Ledger'], $this->models([static::$root.'/legacy']));
    }

    #[Test]
    public function overlapping_paths_find_a_model_once(): void
    {
        $this->assertSame(
            ['DissectDomains\Sales\Models\Invoice', 'DissectDomains\Sales\Models\Quote'],
            $this->models([static::$root.'/Domain/Sales', static::$root.'/Domain/Sales/Models']),
        );
    }

    #[Test]
    public function a_path_that_matches_nothing_is_skipped(): void
    {
        $this->assertSame(
            ['DissectDomains\Accounting\Ledger'],
            $this->models([static::$root.'/does-not-exist', static::$root.'/Domain/*/Nothing', static::$root.'/legacy']),
        );
    }

    #[Test]
    public function without_paths_the_single_models_path_is_read(): void
    {
        // A config published before `models.paths` existed sets only this.
        config()->set('dissect.models_path', static::$root.'/legacy');

        $this->assertSame(['DissectDomains\Accounting\Ledger'], $this->models([]));
    }

    #[Test]
    public function both_invoices_reach_the_graph_as_separate_nodes(): void
    {
        config()->set('dissect.models.paths', [static::$root.'/Domain/*/Models']);

        $ids = array_column($this->getJson('/dissect/schema.json')->assertOk()->json('nodes'), 'id');
        sort($ids);

        $this->assertSame([
            'DissectDomains\Billing\Models\Invoice',
            'DissectDomains\Sales\Models\Invoice',
            'DissectDomains\Sales\Models\Quote',
        ], $ids);
    }
}
