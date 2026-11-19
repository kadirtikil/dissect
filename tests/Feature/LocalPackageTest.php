<?php

namespace KdrDev\Dissect\Tests\Feature;

use KdrDev\Dissect\ProviderTree\NodeKind;
use KdrDev\Dissect\ProviderTree\Origin;
use KdrDev\Dissect\ProviderTree\TreeBuilder;
use KdrDev\Dissect\Routes\ActionResolver;
use KdrDev\Dissect\Routes\RouteExporter;
use KdrDev\Dissect\Support\CodeOwnership;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A module of the application's own, installed by Composer from a `path`
 * repository as a copy. It sits under vendor/ and is still nobody else's code.
 */
class LocalPackageTest extends TestCase
{
    protected static string $root;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::$root = sys_get_temp_dir().'/dissect-local-package-'.uniqid();
        $composer = static::$root.'/vendor/composer';
        $source = static::$root.'/vendor/acme/billing/src';

        mkdir($composer, 0755, true);
        mkdir($source, 0755, true);

        file_put_contents($composer.'/installed.json', json_encode(['packages' => [
            ['name' => 'acme/billing', 'dist' => ['type' => 'path', 'url' => '../packages/billing'], 'install-path' => '../acme/billing'],
        ]]));

        file_put_contents($source.'/InvoiceController.php', <<<'PHP'
            <?php

            namespace DissectLocal\Billing;

            class InvoiceController
            {
                public function index(): array
                {
                    return [];
                }
            }
            PHP);

        require_once $source.'/InvoiceController.php';
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf '.escapeshellarg(static::$root));

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(CodeOwnership::class, new CodeOwnership(static::$root.'/vendor/composer'));
        $this->app->forgetInstance(ActionResolver::class);
        $this->app->forgetInstance(RouteExporter::class);
    }

    protected function defineRoutes($router): void
    {
        $router->get('invoices', [\DissectLocal\Billing\InvoiceController::class, 'index']);
    }

    #[Test]
    public function its_routes_are_filed_under_the_application(): void
    {
        $routes = array_column($this->app->make(RouteExporter::class)->export()['routes'], null, 'id');

        $this->assertSame('app', $routes['GET:invoices']['group']);
    }

    #[Test]
    public function the_provider_trees_treat_its_classes_as_the_applications(): void
    {
        // Origin is what decides whether a tree keeps walking into a class.
        $tree = new TreeBuilder('Workbench\App\Providers\AppServiceProvider', $this->app->make(CodeOwnership::class));
        $id = $tree->named(\DissectLocal\Billing\InvoiceController::class, NodeKind::Concrete, 1, 'certain');

        $this->assertSame(Origin::App, $tree->node($id)?->origin);
    }
}
