<?php

namespace KdrDev\Dissect\Tests\Mcp;

use KdrDev\Dissect\Mcp\DissectServer;
use KdrDev\Dissect\Mcp\Payloads;
use KdrDev\Dissect\Mcp\Tools\Changes;
use KdrDev\Dissect\Mcp\Tools\Check;
use KdrDev\Dissect\Mcp\Tools\ClassReferences;
use KdrDev\Dissect\Mcp\Tools\Domain;
use KdrDev\Dissect\Mcp\Tools\Job;
use KdrDev\Dissect\Mcp\Tools\Model;
use KdrDev\Dissect\Mcp\Tools\Provider;
use KdrDev\Dissect\Mcp\Tools\Route;
use KdrDev\Dissect\Mcp\Tools\Search;
use KdrDev\Dissect\Support\Surfaces;
use KdrDev\Dissect\Tests\TestCase;
use Laravel\Mcp\Server\Registrar;
use PHPUnit\Framework\Attributes\Test;

/**
 * Each tool against the workbench app, read in process: the app under test is
 * the one this test booted. PayloadsTest covers reading through artisan.
 */
class ToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Payloads::class, Payloads::inProcess($this->app->make(Surfaces::class)));
    }

    #[Test]
    public function the_server_is_registered_for_mcp_start_with_every_tool(): void
    {
        $this->assertNotNull($this->app->make(Registrar::class)->getLocalServer('dissect'));

        DissectServer::tools()->assertRegistered([Search::class, Model::class, Route::class, Job::class, Provider::class, Domain::class, ClassReferences::class, Check::class, Changes::class]);
    }

    #[Test]
    public function a_model_is_found_by_short_name_full_name_or_table(): void
    {
        foreach (['Post', 'post', 'Workbench\App\Models\Post', 'posts'] as $name) {
            DissectServer::tool(Model::class, ['name' => $name])
                ->assertOk()
                ->assertSee(['## Post `posts`', '- author → Author (BelongsTo, author_id → id)', '- used by: Author.posts']);
        }
    }

    #[Test]
    public function without_a_name_a_tool_lists_everything_in_one_line_each(): void
    {
        DissectServer::tool(Model::class)
            ->assertOk()
            ->assertSee(['# Models', '7 in all.', '- Post `posts`', '- Tag `tags`'])
            ->assertDontSee('- columns:');

        DissectServer::tool(Route::class)->assertOk()->assertSee(['# Routes', '- `POST api/posts`']);
        DissectServer::tool(Job::class)->assertOk()->assertSee(['# Jobs', '- PublishPost (job)']);
        DissectServer::tool(Provider::class)->assertOk()->assertSee(['# Service providers', '- ContextualServiceProvider']);
    }

    #[Test]
    public function a_partial_name_with_several_matches_says_how_many(): void
    {
        DissectServer::tool(Model::class, ['name' => 'omm'])
            ->assertOk()
            ->assertSee(['## Comment `comments`']);

        // Every JSON:API route under one post: more than are shown in full.
        DissectServer::tool(Route::class, ['query' => 'v1/posts/{post}/'])
            ->assertOk()
            ->assertSee(['routes match "v1/posts/{post}/".', 'The first 5 in full, then the rest by name:']);
    }

    #[Test]
    public function nothing_found_says_where_to_look_instead(): void
    {
        DissectServer::tool(Model::class, ['name' => 'Spaceship'])
            ->assertOk()
            ->assertSee(['No model matches "Spaceship".', 'dissect_search']);
    }

    #[Test]
    public function a_route_is_found_by_name_verb_and_uri_or_controller(): void
    {
        DissectServer::tool(Route::class, ['query' => 'POST api/posts'])
            ->assertOk()
            ->assertSee(['- `POST api/posts`', '  - request ('])
            ->assertDontSee('GET api/posts');

        DissectServer::tool(Route::class, ['query' => 'PostController@store'])->assertOk()->assertSee('- `POST api/posts`');
    }

    #[Test]
    public function a_job_says_who_dispatches_it(): void
    {
        DissectServer::tool(Job::class, ["name" => "PublishPost"])
            ->assertOk()
            ->assertSee(['- **PublishPost** (job) `Workbench\App\Jobs\PublishPost`', '  - dispatched by: ']);
    }

    #[Test]
    public function a_provider_reads_as_its_bindings(): void
    {
        DissectServer::tool(Provider::class, ['name' => 'ContextualServiceProvider'])
            ->assertOk()
            ->assertSee(['contextual ', ' asks)']);
    }

    #[Test]
    public function an_app_without_domains_says_so(): void
    {
        DissectServer::tool(Domain::class)->assertOk()->assertSee('No domains found');
    }

    #[Test]
    public function search_names_every_surface_it_found_something_in(): void
    {
        DissectServer::tool(Search::class, ['query' => 'author'])
            ->assertOk()
            ->assertSee([
                '## Models (dissect_model)',
                '- Author `authors`',
                '- Post.author → Author (BelongsTo)',
                '## Routes (dissect_route)',
                '- `POST api/posts` → PostController@store (takes author_id)',
                '## Jobs (dissect_job)',
                '- SyncAuthorProfile (job) carries Author',
            ]);

        DissectServer::tool(Search::class, ['query' => 'zzz-nothing'])->assertOk()->assertSee('Nothing dissect reads mentions "zzz-nothing".');
    }

    #[Test]
    public function search_needs_a_query(): void
    {
        DissectServer::tool(Search::class)->assertHasErrors(['Give a query']);
    }

    #[Test]
    public function an_app_that_cannot_be_read_is_an_error_not_a_crash(): void
    {
        $this->app->instance(Payloads::class, Payloads::fresh([PHP_BINARY, '/nonexistent/artisan'], sys_get_temp_dir()));

        DissectServer::tool(Model::class, ['name' => 'Post'])
            ->assertHasErrors(['dissect could not read the app: dissect:export schema failed']);
    }
}
