<?php

namespace KdrDev\Dissect\Tests\Changes;

use KdrDev\Dissect\Changes\Report;
use KdrDev\Dissect\Changes\Snapshot;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SnapshotTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    protected function payloads(): array
    {
        return [
            'schema' => [
                'nodes' => [[
                    'id' => 'App\\Models\\Invoice',
                    'class' => 'App\\Models\\Invoice',
                    'table' => 'invoices',
                    'connection' => 'sqlite',
                    'context' => 'Billing',
                    'columns' => [
                        ['name' => 'total', 'type' => 'integer', 'label' => 'int', 'kind' => 'integer', 'nullable' => false, 'unique' => false, 'increments' => false, 'fillable' => true, 'hidden' => false, 'cast' => null, 'virtual' => false],
                        ['name' => 'id', 'type' => 'integer', 'label' => 'int', 'kind' => 'integer', 'nullable' => false, 'unique' => true, 'increments' => true, 'fillable' => false, 'hidden' => false, 'cast' => 'int', 'virtual' => false],
                    ],
                ]],
                'edges' => [
                    ['source' => 'App\\Models\\Invoice', 'target' => 'App\\Models\\Customer', 'type' => 'BelongsTo', 'name' => 'customer', 'keys' => ['source' => 'customer_id', 'target' => 'id', 'via' => null]],
                ],
                'generated_at' => '2026-11-22T10:00:00+00:00',
            ],
            'routes' => [
                'routes' => [[
                    'id' => 'POST:api/invoices',
                    'methods' => ['POST'],
                    'uri' => 'api/invoices',
                    'name' => 'invoices.store',
                    'middleware' => ['api', 'auth:sanctum'],
                    'action' => ['type' => 'controller', 'class' => 'App\\Http\\Controllers\\InvoiceController', 'method' => 'store', 'label' => 'InvoiceController@store'],
                    'context' => 'Billing',
                    'parameters' => [],
                    'request' => ['source' => 'form-request', 'class' => 'App\\Http\\Requests\\StoreInvoice', 'confidence' => 'certain', 'fields' => [
                        ['path' => 'total', 'type' => 'integer', 'required' => true, 'rules' => ['required', 'integer']],
                    ]],
                    'response' => ['source' => 'resource', 'class' => 'App\\Http\\Resources\\InvoiceResource', 'status' => 201, 'confidence' => 'certain', 'fields' => [
                        ['path' => 'id', 'kind' => 'scalar', 'conditional' => false],
                    ]],
                ], [
                    'id' => 'GET:up',
                    'name' => null,
                    'middleware' => [],
                    'action' => ['type' => 'closure', 'class' => null, 'method' => null, 'label' => 'web.php:12'],
                    'context' => null,
                    'parameters' => [],
                    'request' => ['source' => 'none', 'class' => null, 'confidence' => 'certain', 'fields' => []],
                    'response' => ['source' => 'unknown', 'class' => null, 'status' => null, 'confidence' => 'unknown', 'fields' => []],
                ]],
            ],
            'jobs' => [
                'jobs' => [[
                    'id' => 'App\\Jobs\\SendInvoice',
                    'kind' => 'job',
                    'context' => 'Billing',
                    'queue' => 'mail',
                    'connection' => null,
                    'retry' => ['tries' => 3, 'timeout' => null, 'maxExceptions' => null, 'backoff' => null, 'retryUntil' => false],
                    'traits' => ['batchable' => false, 'unique' => false, 'encrypted' => false, 'afterCommit' => true],
                    'payload' => [['name' => 'invoice', 'type' => 'App\\Models\\Invoice', 'model' => 'App\\Models\\Invoice', 'optional' => false, 'variadic' => false, 'default' => null]],
                    'middleware' => [],
                    'events' => [],
                    'confidence' => 'certain',
                    'dispatched_by' => [
                        ['file' => '/home/someone/app/Http/Controllers/InvoiceController.php', 'line' => 30, 'label' => 'InvoiceController@send'],
                        ['file' => '/home/someone/app/Http/Controllers/InvoiceController.php', 'line' => 41, 'label' => 'InvoiceController@send'],
                        // In a route-file closure, named by where it sits.
                        ['file' => 'routes/api.php', 'line' => 25, 'label' => 'api.php:25'],
                    ],
                ]],
            ],
            'providers' => [
                'providers' => [[
                    'id' => 'App\\Providers\\BillingServiceProvider',
                    'file' => '/home/someone/app/Providers/BillingServiceProvider.php',
                    'context' => 'Billing',
                    'deferred' => false,
                    'provides' => [],
                    'side_effects' => [],
                    'nodes' => [['id' => 'App\\Contracts\\Gateway', 'file' => '/home/someone/app/Contracts/Gateway.php']],
                    'edges' => [['source' => 'App\\Contracts\\Gateway', 'target' => 'App\\Services\\StripeGateway', 'kind' => 'bind', 'confidence' => 'certain']],
                ]],
            ],
        ];
    }

    #[Test]
    public function each_part_is_keyed_by_the_id_the_page_uses(): void
    {
        $snapshot = Snapshot::of($this->payloads());

        $this->assertSame(Snapshot::VERSION, $snapshot['version']);
        $this->assertSame(['App\\Models\\Invoice'], array_keys($snapshot['models']));
        $this->assertSame(['GET:up', 'POST:api/invoices'], array_keys($snapshot['routes']));
        $this->assertSame(['App\\Jobs\\SendInvoice'], array_keys($snapshot['jobs']));
        $this->assertSame(['App\\Providers\\BillingServiceProvider'], array_keys($snapshot['providers']));
        $this->assertSame(['models' => 1, 'routes' => 2, 'jobs' => 1, 'providers' => 1], Snapshot::counts($snapshot));
    }

    #[Test]
    public function a_model_is_its_table_columns_and_relations(): void
    {
        $model = Snapshot::of($this->payloads())['models']['App\\Models\\Invoice'];

        $this->assertSame(['id', 'total'], array_keys($model['columns']));
        $this->assertSame(
            ['type' => 'integer', 'kind' => 'integer', 'nullable' => false, 'unique' => false, 'increments' => false, 'fillable' => true, 'hidden' => false, 'cast' => null, 'virtual' => false],
            $model['columns']['total'],
        );
        $this->assertSame(['type' => 'BelongsTo', 'target' => 'App\\Models\\Customer', 'keys' => ['source' => 'customer_id', 'target' => 'id', 'via' => null]], $model['relations']['customer']);
    }

    #[Test]
    public function a_route_is_what_a_client_of_it_depends_on(): void
    {
        $route = Snapshot::of($this->payloads())['routes']['POST:api/invoices'];

        $this->assertSame('invoices.store', $route['name']);
        $this->assertSame('App\\Http\\Controllers\\InvoiceController@store', $route['action']);
        $this->assertSame(['api', 'auth:sanctum'], $route['middleware']);
        $this->assertSame(['type' => 'integer', 'required' => true, 'rules' => ['required', 'integer']], $route['request']['fields']['total']);
        $this->assertSame(['source' => 'resource', 'class' => 'App\\Http\\Resources\\InvoiceResource', 'status' => 201, 'fields' => ['id' => ['kind' => 'scalar', 'conditional' => false]]], $route['response']);
    }

    #[Test]
    public function nothing_that_moves_without_the_code_changing_is_kept(): void
    {
        $payloads = $this->payloads();
        $snapshot = Snapshot::of($payloads);
        $json = Snapshot::encode($snapshot);

        // When it was read, where it was read, how sure the reading was, and
        // the line a closure or a dispatch happens to sit on.
        $this->assertStringNotContainsString('generated_at', $json);
        $this->assertStringNotContainsString('/home/someone', $json);
        $this->assertStringNotContainsString('confidence', $json);
        $this->assertSame('closure', $snapshot['routes']['GET:up']['action']);
        $this->assertSame(['InvoiceController@send', 'api.php'], $snapshot['jobs']['App\\Jobs\\SendInvoice']['dispatched_by']);

        $payloads['schema']['generated_at'] = '2027-01-01T00:00:00+00:00';
        $payloads['routes']['routes'][1]['action']['label'] = 'web.php:40';
        $payloads['jobs']['jobs'][0]['dispatched_by'][0]['line'] = 99;
        $payloads['jobs']['jobs'][0]['dispatched_by'][2] = ['file' => 'routes/api.php', 'line' => 31, 'label' => 'api.php:31'];

        $this->assertSame($json, Snapshot::encode(Snapshot::of($payloads)));
    }

    #[Test]
    public function a_provider_is_what_it_binds(): void
    {
        $provider = Snapshot::of($this->payloads())['providers']['App\\Providers\\BillingServiceProvider'];

        $this->assertSame(['App\\Contracts\\Gateway -> App\\Services\\StripeGateway' => ['kind' => 'bind']], $provider['edges']);
        $this->assertArrayNotHasKey('nodes', $provider);
    }

    #[Test]
    public function an_empty_map_is_left_out_and_an_empty_part_is_still_a_map(): void
    {
        $snapshot = Snapshot::of($this->payloads());

        $this->assertArrayNotHasKey('parameters', $snapshot['routes']['POST:api/invoices']);
        $this->assertArrayNotHasKey('fields', $snapshot['routes']['GET:up']['request']);
        $this->assertArrayNotHasKey('side_effects', $snapshot['providers']['App\\Providers\\BillingServiceProvider']);
        // Lists stay, empty or not: [] is unambiguous for a list.
        $this->assertSame([], $snapshot['routes']['GET:up']['middleware']);

        $json = Snapshot::encode(Snapshot::of(['schema' => ['nodes' => [], 'edges' => []]]));

        $this->assertStringContainsString('"models": {}', $json);
        $this->assertStringContainsString('"providers": {}', $json);
    }

    #[Test]
    public function it_is_written_one_line_per_column_field_or_setting(): void
    {
        $json = Snapshot::encode(Snapshot::of($this->payloads()));

        $this->assertStringContainsString(
            "\n                \"total\": {\"type\": \"integer\", \"kind\": \"integer\", \"nullable\": false, \"unique\": false, \"increments\": false, \"fillable\": true, \"hidden\": false, \"cast\": null, \"virtual\": false}\n",
            $json,
        );
        $this->assertStringContainsString("\n            \"middleware\": [\"api\", \"auth:sanctum\"],\n", $json);
        $this->assertStringContainsString("\n            \"retry\": {\"tries\": 3, \"timeout\": null, \"maxExceptions\": null, \"backoff\": null, \"retryUntil\": false},\n", $json);
        $this->assertStringContainsString("\n                \"App\\\\Contracts\\\\Gateway -> App\\\\Services\\\\StripeGateway\": {\"kind\": \"bind\"}\n", $json);
        $this->assertStringEndsWith("}\n", $json);
    }

    #[Test]
    public function what_is_written_reads_back_as_it_was(): void
    {
        $snapshot = Snapshot::of($this->payloads());
        $json = Snapshot::encode($snapshot);

        $this->assertSame($snapshot, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($json, Snapshot::encode(json_decode($json, true)));
    }

    #[Test]
    public function dissects_own_routes_are_left_out(): void
    {
        $payloads = $this->payloads();
        $payloads['routes']['routes'][] = [
            'id' => 'GET|HEAD:dissect/changes.json',
            'name' => 'dissect.changes',
            'middleware' => ['web'],
            'action' => ['type' => 'controller', 'class' => 'KdrDev\\Dissect\\Http\\Controllers\\DissectController', 'method' => 'changesJson', 'label' => 'DissectController@changesJson'],
        ];

        $this->assertSame(['GET:up', 'POST:api/invoices'], array_keys(Snapshot::of($payloads)['routes']));

        // And from the real app, where the page is switched on.
        $this->assertNotEmpty(array_filter($this->app['router']->getRoutes()->getRoutes(), fn ($route) => str_starts_with((string) $route->getName(), 'dissect.')));
        $taken = $this->app->make(Snapshot::class)->take();
        $this->assertSame([], array_filter(array_keys($taken['routes']), fn (string $id) => str_contains($id, ':dissect')));
    }

    #[Test]
    public function taking_one_of_the_workbench_twice_gives_the_same_bytes(): void
    {
        $first = Snapshot::encode($this->app->make(Snapshot::class)->take());
        $this->app['cache']->flush();
        $second = Snapshot::encode($this->app->make(Snapshot::class)->take());

        $this->assertSame($first, $second);
        $this->assertStringContainsString('"Workbench\\\\App\\\\Models\\\\Post": {', $first);
        $this->assertStringNotContainsString(base_path(), $first);
        $this->assertStringNotContainsString(dirname(__DIR__, 2), $first);
    }

    /** @param  array<int, string>  $unread  What each binding that could not be named says. */
    protected function mailProvider(string $consumer, array $unread = []): array
    {
        $provider = 'App\\Providers\\MailServiceProvider';

        return Snapshot::of(['providers' => ['providers' => [[
            'id' => $provider,
            'nodes' => array_map(fn (string $label, int $i) => ['id' => 'unresolved:'.$i, 'label' => $label], $unread, array_keys($unread)),
            'edges' => [
                ['source' => 'App\\Contracts\\Mailer', 'target' => 'App\\Mail\\SmtpMailer', 'kind' => 'bind', 'consumer' => null],
                ['source' => 'App\\Contracts\\Mailer', 'target' => 'App\\Mail\\SmtpMailer', 'kind' => 'contextual', 'consumer' => $consumer],
                ...array_map(fn (int $i) => ['source' => $provider, 'target' => 'unresolved:'.$i, 'kind' => 'bind', 'consumer' => null], array_keys($unread)),
            ],
        ]]]]);
    }

    #[Test]
    public function a_binding_for_one_consumer_is_its_own_entry(): void
    {
        $edges = $this->mailProvider('App\\Mail\\ReportMailer')['providers']['App\\Providers\\MailServiceProvider']['edges'];

        $this->assertSame([
            'App\\Contracts\\Mailer -> App\\Mail\\SmtpMailer' => ['kind' => 'bind'],
            'App\\Contracts\\Mailer -> App\\Mail\\SmtpMailer when App\\Mail\\ReportMailer' => ['kind' => 'contextual'],
        ], $edges);

        // Given to another consumer: one binding gone, one new.
        $changes = Report::between($this->mailProvider('App\\Mail\\ReportMailer'), $this->mailProvider('App\\Mail\\InvoiceMailer'))['changes'];

        $this->assertSame([
            [['edges', 'App\\Contracts\\Mailer -> App\\Mail\\SmtpMailer when App\\Mail\\InvoiceMailer'], 'added'],
            [['edges', 'App\\Contracts\\Mailer -> App\\Mail\\SmtpMailer when App\\Mail\\ReportMailer'], 'removed'],
        ], array_map(fn (array $fact) => [$fact['path'], $fact['change']], $changes[0]['facts']));
    }

    #[Test]
    public function a_binding_that_could_not_be_named_is_named_by_what_it_says(): void
    {
        $both = $this->mailProvider('App\\Mail\\ReportMailer', ['$this->app->make($a)', '$this->app->make($b)', '$this->app->make($b)']);
        $edges = array_keys($both['providers']['App\\Providers\\MailServiceProvider']['edges']);

        $this->assertContains('App\\Providers\\MailServiceProvider -> ? $this->app->make($a)', $edges);
        $this->assertContains('App\\Providers\\MailServiceProvider -> ? $this->app->make($b) #2', $edges);

        // Taking the first one out is that one gone, not the others renamed.
        $later = $this->mailProvider('App\\Mail\\ReportMailer', ['$this->app->make($b)', '$this->app->make($b)']);
        $facts = Report::between($both, $later)['changes'][0]['facts'];

        $this->assertCount(1, $facts);
        $this->assertSame(['edges', 'App\\Providers\\MailServiceProvider -> ? $this->app->make($a)'], $facts[0]['path']);
        $this->assertSame('removed', $facts[0]['change']);
    }

    #[Test]
    public function routes_only_some_machines_register_are_left_out(): void
    {
        $route = fn (string $id, ?string $class, ?string $name = null) => [
            'id' => $id, 'uri' => substr($id, strpos($id, ':') + 1), 'name' => $name,
            'action' => ['type' => $class === null ? 'closure' : 'controller', 'class' => $class, 'method' => 'index', 'label' => 'x'],
        ];

        $routes = Snapshot::of(['routes' => ['routes' => [
            $route('GET:telescope/{view?}', 'Laravel\\Telescope\\Http\\Controllers\\HomeController', 'telescope'),
            $route('GET:_debugbar/open', 'Barryvdh\\Debugbar\\Controllers\\OpenHandlerController', 'debugbar.openhandler'),
            $route('GET:admin/tools/phpinfo', null),
            $route('GET:tools.cache', null, 'tools.cache'),
            $route('GET:v1/posts', 'LaravelJsonApi\\Laravel\\Http\\Controllers\\JsonApiController'),
        ]]], [...config('dissect.snapshot_ignore_routes'), 'admin/tools/*', 'tools.*'])['routes'];

        // A package's controller alone is no reason: these are the app's API.
        $this->assertSame(['GET:v1/posts'], array_keys($routes));
    }

    #[Test]
    public function the_workbench_snapshot_keeps_its_json_api_routes(): void
    {
        $this->assertContains('GET:v1/posts', array_keys($this->app->make(Snapshot::class)->take()['routes']));
    }
}
