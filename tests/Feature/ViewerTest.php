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
    public function it_serves_every_chunk_the_entry_imports(): void
    {
        // The lazily loaded pages are fetched by the browser, not by the Blade
        // view, so a chunk the allow-list does not cover fails only once
        // somebody opens that page. Reading the imports out of the built entry
        // means a page added later is covered without touching this test.
        $entry = file_get_contents(dirname(__DIR__, 2).'/dist/dissect.js');

        preg_match_all('/["\'`]\.\/(dissect-[A-Za-z0-9_-]+\.js)["\'`]/', (string) $entry, $matches);

        $chunks = array_unique($matches[1]);

        $this->assertNotEmpty($chunks, 'the entry should split its pages into chunks');

        foreach ($chunks as $chunk) {
            $this->get('/dissect/assets/'.$chunk)->assertOk();
        }
    }

    #[Test]
    public function it_revalidates_the_entry_and_stylesheet_and_caches_chunks_for_good(): void
    {
        // The entry and the stylesheet keep their names across releases, so a
        // browser has to ask again; a chunk's name changes with its contents,
        // so it never has to.
        foreach (['dissect.js', 'dissect.css'] as $file) {
            $first = $this->get('/dissect/assets/'.$file)->assertOk();

            $this->assertStringContainsString('no-cache', (string) $first->headers->get('Cache-Control'));
            $this->assertNotEmpty($etag = $first->headers->get('ETag'));

            $this->get('/dissect/assets/'.$file, ['If-None-Match' => $etag])->assertStatus(304);
        }

        $chunk = basename((string) collect(glob(dirname(__DIR__, 2).'/dist/dissect-*.js'))->first());

        $this->assertStringContainsString(
            'immutable',
            (string) $this->get('/dissect/assets/'.$chunk)->assertOk()->headers->get('Cache-Control'),
        );
    }

    #[Test]
    public function it_serves_no_asset_outside_the_allow_list(): void
    {
        $this->get('/dissect/assets/index.html')->assertNotFound();
        $this->get('/dissect/assets/dissect-Missing.js')->assertNotFound();
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

    #[Test]
    public function a_malformed_layout_is_cleaned_rather_than_failing_the_request(): void
    {
        // Both used to be 500s: invalid UTF-8 throws in json_encode(), and an
        // out-of-range float is a cast warning Laravel promotes to an error.
        $this->post('/dissect/layout', [
            'positions' => [
                "Po\xffst" => ['x' => 1, 'y' => 1],
                'Comment' => ['x' => 1e300, 'y' => 1],
                'Tag' => ['x' => 5, 'y' => 6],
            ],
        ])->assertOk()->assertJson(['ok' => true, 'saved' => 1]);
    }

    #[Test]
    public function it_reports_a_layout_written_by_a_newer_version_instead_of_replacing_it(): void
    {
        // The upgrade hazard, end to end: a developer running an older dissect
        // against a layout a teammate saved with a newer one gets a refusal the
        // page can show, not a 500 and not a silently truncated file.
        $path = $this->layoutPath($this->app);
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode(['version' => 99, 'positions' => ['Post' => ['x' => 7, 'y' => 9]]]));

        $this->post('/dissect/layout', ['positions' => ['Post' => ['x' => 1, 'y' => 1]]])
            ->assertStatus(409)
            ->assertJson(['ok' => false]);

        $this->assertSame(
            ['Post' => ['x' => 7, 'y' => 9]],
            json_decode((string) file_get_contents($path), true)['positions'],
        );
    }

    #[Test]
    public function a_layout_the_filesystem_will_not_take_is_reported_with_its_reason(): void
    {
        // A file where .dissect-test/ should be, so the directory cannot be
        // created. Before, this was a PHP warning turned 500 with nothing in
        // the body to say which file or why.
        $path = $this->layoutPath($this->app);
        file_put_contents(dirname($path), 'not a directory');

        try {
            $this->post('/dissect/layout', ['positions' => ['Post' => ['x' => 1, 'y' => 1]]])
                ->assertStatus(500)
                ->assertJson(['ok' => false])
                ->assertJsonPath('message', fn (string $message) => str_contains($message, 'layout.json'));
        } finally {
            unlink(dirname($path));
        }
    }

    protected function tearDown(): void
    {
        $path = $this->layoutPath($this->app);

        foreach (glob($path.'*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir(dirname($path));

        parent::tearDown();
    }
}
