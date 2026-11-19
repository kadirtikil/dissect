<?php

namespace KdrDev\Dissect\Tests\Feature;

use KdrDev\Dissect\LayoutRepository;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The upgrade from version 1 state files against the workbench's real models,
 * through the endpoints the page uses.
 */
class StateMigrationTest extends TestCase
{
    #[Test]
    public function a_version_1_layout_reaches_the_page_under_full_class_names(): void
    {
        $path = $this->layoutPath($this->app);
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode(['version' => 1, 'positions' => ['Post' => ['x' => 7, 'y' => 8]]]));

        $layout = $this->app->make(LayoutRepository::class)->get();

        $this->assertSame(2, $layout['version']);
        $this->assertSame(['Workbench\App\Models\Post' => ['x' => 7, 'y' => 8]], $layout['positions']);

        // And inlined into the page as such.
        $this->get('/dissect')->assertOk()->assertSee('"Workbench\\\\App\\\\Models\\\\Post":{"x":7,"y":8}', escape: false);
    }

    #[Test]
    public function a_version_1_save_is_stored_under_full_class_names(): void
    {
        $this->postJson('/dissect/views', [
            'version' => 1,
            'views' => [['id' => 'blog', 'name' => 'Blog', 'models' => ['Post', 'Tag']]],
        ])->assertOk();

        $this->getJson('/dissect/views.json')
            ->assertJsonPath('views.0.models', ['Workbench\App\Models\Post', 'Workbench\App\Models\Tag']);
    }

    #[Test]
    public function a_current_save_is_stored_as_sent(): void
    {
        $this->postJson('/dissect/layout', [
            'version' => 2,
            'positions' => ['Workbench\App\Models\Post' => ['x' => 1, 'y' => 2]],
        ])->assertOk();

        $this->assertSame(
            ['Workbench\App\Models\Post' => ['x' => 1, 'y' => 2]],
            $this->app->make(LayoutRepository::class)->get()['positions'],
        );
    }

    protected function tearDown(): void
    {
        $directory = dirname($this->layoutPath($this->app));

        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }

        parent::tearDown();
    }
}
