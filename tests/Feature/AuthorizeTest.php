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
