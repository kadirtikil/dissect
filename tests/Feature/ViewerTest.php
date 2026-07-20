<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A smoke test over the whole vertical slice: routes, the exporter reading real
 * fixture models against a real schema, and the assets the page depends on.
 */
class ViewerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_the_viewer_page(): void
    {
        $response = $this->get('/dissect');

        $response->assertOk();
        $response->assertSee('__DISSECT__', escape: false);
    }

    #[Test]
    public function it_exports_models_and_their_relations(): void
    {
        $schema = $this->get('/dissect/schema.json')->assertOk()->json();

        $names = array_column($schema['nodes'], 'id');

        $this->assertContains('Post', $names);
        $this->assertContains('Author', $names);
        $this->assertNotEmpty($schema['edges']);

        $post = collect($schema['nodes'])->firstWhere('id', 'Post');

        $this->assertSame('posts', $post['table']);
        $this->assertNotEmpty($post['columns'], 'columns should be read from the real schema');
    }

    #[Test]
    public function it_ships_the_compiled_assets_the_page_references(): void
    {
        // The package serves dist/ straight out of vendor/, so a release that
        // forgot to build would 404 here rather than in someone else's app.
        $this->get('/dissect/assets/dissect.js')->assertOk();
        $this->get('/dissect/assets/dissect.css')->assertOk();
    }

    #[Test]
    public function it_serves_no_asset_outside_the_allow_list(): void
    {
        $this->get('/dissect/assets/index.html')->assertNotFound();
    }

    #[Test]
    public function it_round_trips_a_layout(): void
    {
        $this->post('/dissect/layout', [
            'positions' => ['Post' => ['x' => 120, 'y' => 40]],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->get('/dissect')->assertOk()->assertSee('"x":120', escape: false);
    }

    #[Test]
    public function it_rejects_a_layout_that_is_not_an_object(): void
    {
        $this->post('/dissect/layout', ['positions' => 'nope'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    protected function tearDown(): void
    {
        $path = $this->layoutPath($this->app);

        if (is_file($path)) {
            unlink($path);
            @rmdir(dirname($path));
        }

        parent::tearDown();
    }
}
