<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use KdrDev\Dissect\ProviderTree\ProviderInspector;
use KdrDev\Dissect\Routes\ResponseAnalyzer;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Workbench\App\Jobs\PublishPost;
use Workbench\App\Providers\BindingServiceProvider;
use Workbench\App\Providers\SiblingServiceProvider;

/**
 * A file that does not load costs its own row, never the surface.
 *
 * This tool is open while code is being written — by a person between
 * keystrokes, or by an agent half way through a change — so a file that does
 * not parse, or extends a class that has just been deleted, is a normal state
 * rather than an edge case. Asking whether such a class exists autoloads it,
 * and autoloading it throws a ParseError or an Error. Each surface is asserted
 * to answer 200 with everything else still on it.
 *
 * The fixtures are written to a temporary directory under their own namespace,
 * served by an autoloader registered for this class only, so the workbench app
 * never contains a file that breaks the other suites.
 */
class BrokenFilesTest extends TestCase
{
    protected static string $root;

    /** @var callable(string): void */
    protected static $autoloader;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::$root = sys_get_temp_dir().'/dissect-broken-'.uniqid();

        $files = [
            // Saved mid-keystroke: the class body never closes.
            'Models/HalfSaved.php' => '<?php namespace DissectBroken\Models; use Illuminate\Database\Eloquent\Model; class HalfSaved extends Model { public function',
            // Its parent was deleted in the same change.
            'Models/Orphaned.php' => '<?php namespace DissectBroken\Models; class Orphaned extends \DissectBroken\Gone\BaseModel {}',
            'Models/Healthy.php' => '<?php namespace DissectBroken\Models; use Illuminate\Database\Eloquent\Model; class Healthy extends Model { protected $table = "posts"; }',

            'Jobs/HalfSaved.php' => '<?php namespace DissectBroken\Jobs; use Illuminate\Contracts\Queue\ShouldQueue; class HalfSaved implements ShouldQueue { public function handle(',
            'Jobs/Orphaned.php' => '<?php namespace DissectBroken\Jobs; class Orphaned implements \DissectBroken\Gone\ShouldQueue {}',

            'Providers/HalfSaved.php' => '<?php namespace DissectBroken\Providers; use Illuminate\Support\ServiceProvider; class HalfSaved extends ServiceProvider { public function register(',

            'Requests/HalfSaved.php' => '<?php namespace DissectBroken\Requests; use Illuminate\Foundation\Http\FormRequest; class HalfSaved extends FormRequest { public function rules(',
            'Http/BrokenController.php' => '<?php namespace DissectBroken\Http; class BrokenController { public function store(\DissectBroken\Requests\HalfSaved $request) { return response()->noContent(); } }',
        ];

        foreach ($files as $path => $contents) {
            @mkdir(dirname(static::$root.'/'.$path), 0755, true);
            file_put_contents(static::$root.'/'.$path, $contents);
        }

        static::$autoloader = static function (string $class): void {
            if (! str_starts_with($class, 'DissectBroken\\')) {
                return;
            }

            $file = static::$root.'/'.str_replace('\\', '/', substr($class, strlen('DissectBroken\\'))).'.php';

            if (is_file($file)) {
                require $file;
            }
        };

        spl_autoload_register(static::$autoloader);
    }

    public static function tearDownAfterClass(): void
    {
        spl_autoload_unregister(static::$autoloader);
        (new Filesystem)->deleteDirectory(static::$root);

        parent::tearDownAfterClass();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('dissect.models_path', static::$root.'/Models');
        $app['config']->set('dissect.models_namespace', 'DissectBroken\\Models');

        $app['config']->push('dissect.jobs.paths', static::$root.'/Jobs');
        $app['config']->push('dissect.providers.paths', static::$root.'/Providers');
    }

    protected function defineRoutes($router): void
    {
        $router->post('/broken-request', [\DissectBroken\Http\BrokenController::class, 'store']);
    }

    #[Test]
    public function the_graph_survives_a_model_that_does_not_load(): void
    {
        $response = $this->getJson('/dissect/schema.json')->assertOk();

        $this->assertSame(['Healthy'], array_column($response->json('nodes'), 'id'));
    }

    #[Test]
    public function the_index_page_survives_a_model_that_does_not_load(): void
    {
        // The page inlines the graph, so it fails exactly when the export does.
        $this->get('/dissect')->assertOk();
    }

}
