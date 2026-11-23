<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use KdrDev\Dissect\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Registration is an environment check; this is the access control that holds
 * when the environment is wrong. Outside local, nothing is served until the
 * application defines the `viewDissect` gate and it says yes.
 */
class AuthorizeTest extends TestCase
{
    /** No gate unless a test defines one. */
    protected function defineGate(): void {}

    /** @return array<string, array{0: string, 1: string}> */
    public static function surfaces(): array
    {
        return [
            'page' => ['get', '/dissect'],
            'schema' => ['get', '/dissect/schema.json'],
            'queue' => ['get', '/dissect/queue.json'],
            'asset' => ['get', '/dissect/assets/dissect.js'],
            'layout write' => ['post', '/dissect/layout'],
            'views write' => ['post', '/dissect/views'],
            'changes' => ['get', '/dissect/changes.json'],
            'openapi' => ['get', '/dissect/openapi.json'],
            'start watching' => ['post', '/dissect/changes/watch'],
            'stop watching' => ['delete', '/dissect/changes/watch'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function nothing_is_served_outside_local_without_a_gate(string $method, string $uri): void
    {
        $this->{$method.'Json'}($uri, ['positions' => [], 'views' => []])->assertForbidden();
    }

    #[Test]
    public function a_gate_that_says_no_is_respected(): void
    {
        Gate::define('viewDissect', fn ($user = null) => false);

        $this->get('/dissect')->assertForbidden();
    }

    #[Test]
    public function a_gate_that_says_yes_opens_it(): void
    {
        Gate::define('viewDissect', fn ($user = null) => true);

        $this->get('/dissect')->assertOk();
        $this->postJson('/dissect/layout', ['positions' => []])->assertOk();
    }

    #[Test]
    public function the_gate_is_asked_about_the_signed_in_user(): void
    {
        Gate::define('viewDissect', fn (User $user) => $user->getAuthIdentifier() === 7);

        // A gate whose user is not nullable refuses guests outright.
        $this->get('/dissect')->assertForbidden();

        $this->actingAs((new User)->forceFill(['id' => 8]))->get('/dissect')->assertForbidden();
        $this->actingAs((new User)->forceFill(['id' => 7]))->get('/dissect')->assertOk();
    }

    #[Test]
    public function local_needs_no_gate(): void
    {
        $this->app['env'] = 'local';

        $this->get('/dissect')->assertOk();
    }

    /** @return array<string, array{0: string}> */
    public static function ownNames(): array
    {
        return [
            'localhost' => ['http://localhost:8000'],
            'a .localhost name' => ['http://shop.localhost'],
            'a .test name' => ['http://Shop.Test'],
            'IPv4 loopback' => ['http://127.0.0.1:8000'],
            'another 127 address' => ['http://127.0.1.1'],
            'IPv6 loopback' => ['http://[::1]:8000'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('ownNames')]
    public function local_answers_to_the_machines_own_names(string $origin): void
    {
        $this->app['env'] = 'local';

        $this->get($origin.'/dissect/schema.json')->assertOk();
    }

    #[Test]
    public function local_does_not_answer_to_another_name(): void
    {
        // A page on attacker.example whose name was pointed at 127.0.0.1:
        // the browser reaches this server, but still says whose page it is.
        $this->app['env'] = 'local';

        $this->get('http://attacker.example/dissect/schema.json')->assertForbidden();
        $this->get('http://attacker.example/dissect')->assertForbidden();

        // Refused here even with a token, which the page above would have
        // handed over: CSRF is not what keeps the write out.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
            ->postJson('http://attacker.example/dissect/layout', ['positions' => []])
            ->assertForbidden();

        // Nor to a name that only contains one of the trusted ones.
        $this->get('http://localhost.attacker.example/dissect')->assertForbidden();
        $this->get('http://127.0.0.1.attacker.example/dissect')->assertForbidden();
    }

    #[Test]
    public function the_refusal_in_local_says_how_to_allow_the_name(): void
    {
        $this->app['env'] = 'local';

        $this->getJson('http://192.168.1.20/dissect/schema.json')
            ->assertForbidden()
            ->assertJsonPath('message', 'dissect only answers to this machine\'s own names in local. Add "192.168.1.20" to dissect.local_hosts to open it from here.');
    }

    #[Test]
    public function local_answers_to_app_urls_host_and_the_listed_ones(): void
    {
        $this->app['env'] = 'local';
        config(['app.url' => 'https://shop.ddev.site', 'dissect.local_hosts' => ['192.168.1.20', ' Phone.Lan ']]);

        $this->get('https://shop.ddev.site/dissect')->assertOk();
        $this->get('http://192.168.1.20/dissect')->assertOk();
        $this->get('http://phone.lan/dissect')->assertOk();
        $this->get('http://other.lan/dissect')->assertForbidden();
    }

    #[Test]
    public function another_name_in_local_can_still_pass_the_gate(): void
    {
        $this->app['env'] = 'local';
        Gate::define('viewDissect', fn ($user = null) => true);

        $this->get('http://staging.example.com/dissect')->assertOk();
    }

    #[Test]
    #[DefineEnvironment('withoutConfiguredMiddleware')]
    public function emptying_the_middleware_list_does_not_remove_the_check(): void
    {
        $this->get('/dissect')->assertForbidden();
    }

    protected function tearDown(): void
    {
        // The one write that is let through lands in the shared test directory.
        $directory = dirname($this->layoutPath($this->app));

        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($directory);

        parent::tearDown();
    }

    protected function withoutConfiguredMiddleware($app): void
    {
        $app['config']->set('dissect.middleware', []);
    }
}
