<?php

namespace KdrDev\Dissect\Tests;

use KdrDev\Dissect\LayoutRepository;
use KdrDev\Dissect\ViewRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use RuntimeException;

/**
 * Version 1 of both files keyed models by short class name; version 2 by full
 * class name. Somebody's arranged layout and curated views must come through
 * the upgrade, and where a short name is ambiguous, the result must not be a
 * guess dressed up as an answer.
 */
class StateMigrationTest extends PhpUnitTestCase
{
    protected string $directory;

    /** Two models share the name Invoice; the rest are unique. */
    protected const MODELS = [
        'App\Models\Post',
        'App\Models\Author',
        'App\Billing\Invoice',
        'App\Sales\Invoice',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/dissect-migration-'.uniqid();
        mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    protected function write(string $name, array $contents): string
    {
        file_put_contents($path = $this->directory.'/'.$name, json_encode($contents));

        return $path;
    }

    protected function layout(string $path, ?array $models = self::MODELS): LayoutRepository
    {
        return new LayoutRepository($path, $models === null ? null : fn () => $models);
    }

    protected function views(string $path, ?array $models = self::MODELS): ViewRepository
    {
        return new ViewRepository($path, $models === null ? null : fn () => $models);
    }

    #[Test]
    public function a_version_1_layout_reads_back_under_full_class_names(): void
    {
        $path = $this->write('layout.json', ['version' => 1, 'positions' => [
            'Post' => ['x' => 10, 'y' => 20],
            'Author' => ['x' => 30, 'y' => 40],
        ]]);

        $this->assertSame([
            'App\Models\Post' => ['x' => 10, 'y' => 20],
            'App\Models\Author' => ['x' => 30, 'y' => 40],
        ], $this->layout($path)->get()['positions']);
    }

    #[Test]
    public function an_ambiguous_or_vanished_model_loses_its_old_position(): void
    {
        // Both Invoices on one spot would hide one under the other, and
        // picking one is a guess. Gone means gone; the next save dropped it
        // under version 1 too.
        $path = $this->write('layout.json', ['version' => 1, 'positions' => [
            'Invoice' => ['x' => 1, 'y' => 1],
            'Deleted' => ['x' => 2, 'y' => 2],
            'Post' => ['x' => 3, 'y' => 3],
        ]]);

        $this->assertSame(['App\Models\Post' => ['x' => 3, 'y' => 3]], $this->layout($path)->get()['positions']);
    }

    #[Test]
    public function a_version_1_view_keeps_every_model_its_short_name_could_mean(): void
    {
        // A view showing one model too many is easy to fix. One that quietly
        // lost a member is not.
        $path = $this->write('views.json', ['version' => 1, 'views' => [
            ['id' => 'money', 'name' => 'Money', 'models' => ['Invoice', 'Author', 'Deleted']],
        ]]);

        $this->assertSame(
            ['App\Billing\Invoice', 'App\Sales\Invoice', 'App\Models\Author'],
            $this->views($path)->get()['views'][0]['models'],
        );
    }

    #[Test]
    public function a_view_left_with_no_models_is_dropped(): void
    {
        $path = $this->write('views.json', ['version' => 1, 'views' => [
            ['id' => 'gone', 'name' => 'Gone', 'models' => ['Deleted']],
        ]]);

        $this->assertSame([], $this->views($path)->get()['views']);
    }

    #[Test]
    public function a_version_2_file_is_not_migrated_again(): void
    {
        // In version 2 a short name is a model in the global namespace.
        $path = $this->write('layout.json', ['version' => 2, 'positions' => [
            'Post' => ['x' => 1, 'y' => 1],
            'App\Models\Author' => ['x' => 2, 'y' => 2],
        ]]);

        $this->assertSame([
            'Post' => ['x' => 1, 'y' => 1],
            'App\Models\Author' => ['x' => 2, 'y' => 2],
        ], (new LayoutRepository($path, fn () => throw new RuntimeException('no discovery for a current file')))->get()['positions']);
    }

    #[Test]
    public function saving_writes_version_2_and_keeps_the_version_1_file(): void
    {
        $path = $this->write('views.json', $original = ['version' => 1, 'views' => [
            ['id' => 'blog', 'name' => 'Blog', 'models' => ['Post']],
        ]]);

        $repository = $this->views($path);
        $repository->put($repository->get()['views']);

        $this->assertSame(json_encode($original), file_get_contents($path.'.v1.bak'));
        $this->assertSame(2, json_decode(file_get_contents($path), true)['version']);
        $this->assertSame(['App\Models\Post'], $this->views($path, [])->get()['views'][0]['models']);
    }

    #[Test]
    public function a_save_from_a_page_opened_before_the_upgrade_is_migrated_too(): void
    {
        // The tab still runs the old script and sends short names. Stored
        // as they are, they would read as global-namespace models: nothing.
        $path = $this->directory.'/layout.json';

        $this->layout($path)->put(['Post' => ['x' => 5, 'y' => 6]], LayoutRepository::ANY_REVISION, 1);

        $this->assertSame(['App\Models\Post' => ['x' => 5, 'y' => 6]], $this->layout($path)->get()['positions']);
    }

    #[Test]
    public function a_model_list_that_cannot_be_read_does_not_break_the_page(): void
    {
        $path = $this->write('layout.json', ['version' => 1, 'positions' => ['Post' => ['x' => 1, 'y' => 1]]]);

        $repository = new LayoutRepository($path, fn () => throw new RuntimeException('a model that does not boot'));

        $this->assertSame([], $repository->get()['positions']);
    }

    #[Test]
    public function the_model_list_is_read_once(): void
    {
        $path = $this->write('views.json', ['version' => 1, 'views' => [
            ['id' => 'a', 'name' => 'A', 'models' => ['Post', 'Author']],
            ['id' => 'b', 'name' => 'B', 'models' => ['Invoice']],
        ]]);

        $calls = 0;
        $repository = new ViewRepository($path, function () use (&$calls) {
            $calls++;

            return self::MODELS;
        });

        $repository->get();
        $repository->get();

        $this->assertSame(1, $calls);
    }
}
