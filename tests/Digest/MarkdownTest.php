<?php

namespace KdrDev\Dissect\Tests\Digest;

use KdrDev\Dissect\Digest\Markdown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The digest says what the payload says, in as few lines as it can.
 *
 * Payloads here are built by hand, cut to the fields each line reads, so a
 * test names exactly what it depends on.
 */
class MarkdownTest extends TestCase
{
    protected Markdown $markdown;

    protected function setUp(): void
    {
        $this->markdown = new Markdown;
    }

    // ── Models ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    protected function schema(): array
    {
        $column = fn (string $name, string $label, array $flags = []) => ['name' => $name, 'label' => $label] + $flags;

        return [
            'nodes' => [
                [
                    'id' => 'App\Billing\Invoice',
                    'class' => 'App\Billing\Invoice',
                    'table' => 'invoices',
                    'context' => 'Billing',
                    'columns' => [
                        $column('id', 'bigint', ['increments' => true, 'unique' => true, 'cast' => 'int']),
                        $column('number', 'varchar(32)', ['unique' => true]),
                        $column('customer_id', 'bigint', ['nullable' => true]),
                        $column('secret', 'text', ['hidden' => true]),
                    ],
                ],
                ['id' => 'App\Sales\Invoice', 'class' => 'App\Sales\Invoice', 'table' => 'quotes', 'context' => 'Sales', 'columns' => []],
                ['id' => 'App\Sales\Customer', 'class' => 'App\Sales\Customer', 'table' => 'customers', 'context' => 'Sales', 'columns' => []],
            ],
            'edges' => [
                ['source' => 'App\Billing\Invoice', 'target' => 'App\Sales\Customer', 'name' => 'customer', 'type' => 'BelongsTo', 'keys' => ['source' => 'customer_id', 'target' => 'id', 'via' => null]],
                ['source' => 'App\Billing\Invoice', 'target' => 'App\Billing\Invoice', 'name' => 'billable', 'type' => 'MorphTo', 'keys' => ['source' => 'billable_id', 'target' => null, 'via' => null]],
                ['source' => 'App\Billing\Invoice', 'target' => 'Vendor\Tax\Rate', 'name' => 'rates', 'type' => 'BelongsToMany', 'keys' => ['source' => 'id', 'target' => 'id', 'via' => 'invoice_rate']],
                ['source' => 'App\Billing\Invoice', 'target' => 'App\Sales\Invoice', 'name' => 'quote', 'type' => 'HasOne'],
                ['source' => 'App\Sales\Customer', 'target' => 'App\Billing\Invoice', 'name' => 'invoices', 'type' => 'HasMany', 'keys' => ['source' => 'id', 'target' => 'customer_id', 'via' => null]],
            ],
        ];
    }

    #[Test]
    public function a_model_lists_its_columns_with_what_sets_each_apart(): void
    {
        $schema = $this->schema();
        $out = $this->markdown->model($schema['nodes'][0], $schema);

        $this->assertStringStartsWith("## Billing\\Invoice `invoices`\n`App\\Billing\\Invoice` · domain Billing\n", $out);
        // A primary key is unique by being one; saying both is noise.
        $this->assertStringContainsString('- columns: id bigint pk cast:int; number varchar(32) unique; customer_id bigint nullable; secret text hidden', $out);
    }

    #[Test]
    public function a_relation_says_its_keys_and_where_it_goes(): void
    {
        $schema = $this->schema();
        $out = $this->markdown->model($schema['nodes'][0], $schema);

        $this->assertStringContainsString('- customer → Customer (BelongsTo, customer_id → id) — crosses into Sales', $out);
        $this->assertStringContainsString('- rates → Rate (BelongsToMany, id → id, via invoice_rate) (not in scan)', $out);
        // Same name in two namespaces: each keeps enough to be told apart.
        $this->assertStringContainsString('- quote → Sales\Invoice (HasOne) — crosses into Sales', $out);
        $this->assertStringContainsString('- used by: Customer.invoices', $out);
    }

    #[Test]
    public function a_morph_to_points_at_any_model_not_back_at_itself(): void
    {
        $schema = $this->schema();
        $out = $this->markdown->model($schema['nodes'][0], $schema);

        $this->assertStringContainsString('- billable → any model (MorphTo, by billable_id)', $out);
        $this->assertStringNotContainsString('used by: Billing\Invoice.billable', $out);
    }

    #[Test]
    public function a_model_with_no_columns_says_why_there_may_be_none(): void
    {
        $schema = $this->schema();

        $this->assertStringContainsString('- columns: none read', $this->markdown->model($schema['nodes'][2], $schema));
    }

    #[Test]
    public function the_schema_counts_what_it_lists(): void
    {
        $out = $this->markdown->render('schema', $this->schema());

        $this->assertStringStartsWith("# Models\n\n3 models, 5 relations.\n", $out);
        $this->assertSame(3, substr_count($out, "\n## "));
    }

    // ── Routes ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    protected function route(array $overrides = []): array
    {
        return array_replace([
            'methods' => ['POST'],
            'uri' => 'api/invoices',
            'name' => 'invoices.store',
            'domain' => null,
            'stack' => 'api',
            'group' => 'app',
            'context' => 'Billing',
            'middleware' => ['api', 'auth:sanctum'],
            'action' => ['type' => 'controller', 'class' => 'App\Http\InvoiceController', 'method' => 'store', 'label' => 'InvoiceController@store'],
            'request' => ['source' => 'form-request', 'class' => 'App\Http\StoreInvoice', 'confidence' => 'certain', 'fields' => [
                ['path' => 'number', 'type' => 'string', 'required' => true],
                ['path' => 'note', 'type' => 'string', 'required' => false],
            ]],
            'response' => ['source' => 'resource', 'class' => 'App\Http\InvoiceResource', 'status' => 201, 'confidence' => 'inferred', 'fields' => [
                ['path' => 'id', 'conditional' => false],
                ['path' => 'customer', 'conditional' => true],
            ]],
        ], $overrides);
    }

    #[Test]
    public function a_route_is_one_line_with_what_it_takes_and_returns_under_it(): void
    {
        $this->assertSame(implode("\n", [
            '- `POST api/invoices` invoices.store → InvoiceController@store · api · domain Billing',
            '  - class: App\Http\InvoiceController',
            '  - middleware: api, auth:sanctum',
            '  - request (form-request StoreInvoice): number* string; note string',
            '  - response 201 (resource InvoiceResource inferred): id; customer?',
        ])."\n", $this->markdown->route($this->route()));
    }

    #[Test]
    public function a_route_leaves_out_what_was_not_read(): void
    {
        $out = $this->markdown->route($this->route([
            'name' => null,
            'domain' => 'admin.example.test',
            'group' => 'vendor',
            'context' => null,
            'middleware' => [],
            'action' => ['type' => 'closure', 'class' => null, 'method' => null, 'label' => 'web.php:14'],
            'request' => ['source' => 'none', 'fields' => []],
            'response' => ['source' => 'unknown', 'fields' => []],
        ]));

        $this->assertSame("- `POST admin.example.test/api/invoices` → web.php:14 · api · vendor\n", $out);
    }

    #[Test]
    public function a_long_field_list_is_cut_with_a_count_of_the_rest(): void
    {
        $fields = array_map(fn (int $i) => ['path' => "f{$i}", 'type' => 'string', 'required' => false], range(1, 30));
        $out = $this->markdown->route($this->route(['request' => ['source' => 'form-request', 'class' => null, 'fields' => $fields]]), 3);

        $this->assertStringContainsString('request (form-request): f1 string; f2 string; f3 string; +27 more', $out);
    }

    // ── Jobs ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    protected function job(array $overrides = []): array
    {
        return array_replace([
            'class' => 'App\Jobs\SendInvoice',
            'name' => 'SendInvoice',
            'kind' => 'job',
            'context' => 'Billing',
            'queue' => 'mail',
            'queue_source' => 'property',
            'retry' => ['tries' => 3, 'timeout' => null, 'maxExceptions' => null, 'backoff' => [10, 60], 'retryUntil' => false],
            'traits' => ['batchable' => false, 'unique' => true, 'encrypted' => false, 'afterCommit' => true],
            'models' => ['App\Billing\Invoice'],
            'middleware' => [],
            'dispatched_by' => [
                ['label' => 'InvoiceController@store', 'route' => 'POST:api/invoices', 'file' => 'app/Http/InvoiceController.php', 'line' => 40],
            ],
        ], $overrides);
    }

    #[Test]
    public function a_job_says_how_it_runs_what_it_carries_and_who_sends_it(): void
    {
        $this->assertSame(implode("\n", [
            '- **SendInvoice** (job) `App\Jobs\SendInvoice` · domain Billing',
            '  - queue mail · tries 3 · backoff 10,60 · unique · afterCommit',
            '  - carries: Invoice',
            '  - dispatched by: InvoiceController@store (POST api/invoices) at app/Http/InvoiceController.php:40',
        ])."\n", $this->markdown->job($this->job()));
    }

    #[Test]
    public function a_job_nothing_sends_is_said_to_be_unsent(): void
    {
        $out = $this->markdown->job($this->job(['queue' => null, 'queue_source' => 'default', 'dispatched_by' => []]));

        $this->assertStringContainsString('  - queue default · ', $out);
        $this->assertStringContainsString('dispatched by: nothing found', $out);
    }

    #[Test]
    public function a_job_sent_to_several_queues_says_so(): void
    {
        $this->assertStringContainsString('  - queue mixed', $this->markdown->job($this->job(['queue' => null, 'queue_source' => 'mixed'])));
    }

    // ── Providers ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    protected function provider(): array
    {
        $node = fn (string $id, string $kind) => ['id' => $id, 'kind' => $kind, 'label' => basename(str_replace('\\', '/', $id))];
        $edge = fn (string $source, string $kind, string $target, array $extra = []) => $extra + ['source' => $source, 'kind' => $kind, 'target' => $target, 'confidence' => 'certain', 'consumer' => null];

        return [
            'id' => 'App\Providers\Billing',
            'class' => 'App\Providers\Billing',
            'name' => 'Billing',
            'context' => null,
            'deferred' => true,
            'partial' => false,
            'truncated' => false,
            'side_effects' => ['gates' => 2],
            'provides' => ['App\Contracts\Gateway'],
            'nodes' => [
                $node('App\Providers\Billing', 'provider'),
                $node('App\Contracts\Gateway', 'contract'),
                $node('App\Stripe\StripeGateway', 'concrete'),
                $node('App\Fake\FakeGateway', 'concrete'),
                $node('App\Stripe\Client', 'concrete'),
                $node('App\Providers\Tax', 'provider'),
                $node('tax.rates', 'contract'),
                $node('App\Tax\Rates', 'concrete'),
            ],
            'edges' => [
                $edge('App\Providers\Billing', 'singleton', 'App\Contracts\Gateway'),
                $edge('App\Contracts\Gateway', 'singleton', 'App\Stripe\StripeGateway'),
                $edge('App\Providers\Billing', 'contextual', 'App\Contracts\Gateway', ['consumer' => 'App\Jobs\Refund']),
                $edge('App\Contracts\Gateway', 'contextual', 'App\Fake\FakeGateway', ['consumer' => 'App\Jobs\Refund']),
                $edge('App\Stripe\StripeGateway', 'injects', 'App\Stripe\Client', ['confidence' => 'inferred']),
                $edge('App\Providers\Billing', 'registers', 'App\Providers\Tax'),
                $edge('App\Providers\Tax', 'scoped', 'tax.rates'),
                $edge('tax.rates', 'scoped', 'App\Tax\Rates'),
            ],
        ];
    }

    #[Test]
    public function a_binding_reads_as_one_line_from_contract_to_concrete(): void
    {
        $this->assertSame(implode("\n", [
            '- **Billing** `App\Providers\Billing` · deferred',
            '  - singleton Gateway → StripeGateway',
            '  - contextual Gateway → FakeGateway (when Refund asks)',
            '  - StripeGateway injects Client (inferred)',
            '  - registers Tax',
            '  - Tax: scoped tax.rates → Rates',
            '  - also registers: gates 2',
            '  - provides: Gateway',
        ])."\n", $this->markdown->provider($this->provider()));
    }

    #[Test]
    public function a_binding_is_as_sure_as_its_least_sure_half(): void
    {
        $provider = $this->provider();
        $provider['edges'][1]['confidence'] = 'inferred';

        $this->assertStringContainsString('  - singleton Gateway → StripeGateway (inferred)', $this->markdown->provider($provider));
    }

    #[Test]
    public function a_binding_that_resolves_to_nothing_stays_on_its_own(): void
    {
        $provider = $this->provider();
        $provider['edges'] = [['source' => 'App\Providers\Billing', 'kind' => 'bind', 'target' => 'unresolved:0', 'confidence' => 'unknown']];
        $provider['nodes'][] = ['id' => 'unresolved:0', 'kind' => 'unresolved', 'label' => '$this->app->bind($a, $b)'];

        $this->assertStringContainsString('  - bind $this->app->bind($a, $b) (unknown)', $this->markdown->provider($provider));
    }

    // ── Domains ───────────────────────────────────────────────────────────

    #[Test]
    public function domains_say_how_each_was_found_and_what_to_check(): void
    {
        $out = $this->markdown->render('domains', [
            'enabled' => true,
            'domains' => [
                ['name' => 'Billing', 'sources' => ['modules', 'attribute'], 'roots' => ['Modules/Billing'], 'classes' => ['A', 'B']],
                ['name' => 'Sales', 'sources' => ['config'], 'roots' => [], 'classes' => ['C']],
            ],
            'unassigned' => ['D', 'E', 'F'],
            'findings' => [['class' => 'App\Sales\Invoice', 'file' => 'app/Sales/Invoice.php', 'message' => 'Its folder says Sales, its attribute Billing.']],
            'problems' => ['domains.json: "Shipping" names no folder.'],
        ]);

        $this->assertStringContainsString("- **Billing** (Modules/ folder, #[Domain] attribute) · 2 classes\n  - in: Modules/Billing\n", $out);
        $this->assertStringContainsString("- **Sales** (domains.json) · 1 class\n", $out);
        // The list stays short; one domain at a time lists its classes.
        $this->assertStringNotContainsString('  - A', $out);
        $this->assertStringContainsString("## To check\n- Invoice: Its folder says Sales, its attribute Billing. (app/Sales/Invoice.php)", $out);
        $this->assertStringContainsString("## Problems with domains.json\n- domains.json: \"Shipping\" names no folder.", $out);
        $this->assertStringContainsString('3 classes are in no domain.', $out);
    }

    #[Test]
    public function one_domain_lists_its_classes(): void
    {
        $out = $this->markdown->domain(['name' => 'Sales', 'sources' => ['config'], 'roots' => [], 'classes' => ['App\Sales\Quote']]);

        $this->assertStringEndsWith("  - App\\Sales\\Quote\n", $out);
    }

    #[Test]
    public function domains_switched_off_or_absent_say_so(): void
    {
        $this->assertStringContainsString('switched off', $this->markdown->render('domains', ['enabled' => false]));
        $this->assertStringContainsString('No domains found', $this->markdown->render('domains', ['enabled' => true, 'domains' => [], 'unassigned' => ['A']]));
        $this->assertStringNotContainsString('in no domain', $this->markdown->render('domains', ['enabled' => true, 'domains' => [], 'unassigned' => ['A']]));
    }

    // ── Classes ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    protected function index(): array
    {
        $class = fn (string $id, ?string $context, array $uses = [], string $kind = 'class') => [
            'id' => $id, 'kind' => $kind, 'abstract' => $kind === 'interface', 'file' => 'app/'.basename(str_replace('\\', '/', $id)).'.php', 'line' => 5, 'context' => $context, 'uses' => $uses,
        ];

        return [
            'classes' => [
                $class('App\Billing\Invoice', 'Billing', [
                    ['to' => 'App\Billing\Payment', 'kind' => 'relation', 'lines' => [9]],
                    ['to' => 'App\Sales\Customer', 'kind' => 'relation', 'lines' => [8]],
                    ['to' => 'App\Sales\Customer', 'kind' => 'type', 'lines' => [12, 14]],
                ]),
                $class('App\Billing\Payment', 'Billing', [['to' => 'App\Billing\Invoice', 'kind' => 'relation', 'lines' => [7]]]),
                $class('App\Sales\Customer', 'Sales', [['to' => 'App\Billing\Invoice', 'kind' => 'relation', 'lines' => [6]]]),
                $class('App\Sales\Invoice', 'Sales'),
                $class('App\Support\Clock', null, [], 'interface'),
            ],
            'unreadable' => ['app/Broken.php'],
        ];
    }

    #[Test]
    public function the_class_list_opens_with_how_domains_depend_on_each_other(): void
    {
        $out = $this->markdown->render('classes', $this->index());

        $this->assertStringStartsWith("# Classes\n\n5 classes, 5 references between them.\n1 file could not be parsed: app/Broken.php.\n", $out);
        $this->assertStringContainsString("## Between domains\n- Billing → Sales: 2 (relation 1, type 1)\n- Sales → Billing: 1 (relation 1)\n", $out);
        // A target in another domain says which; shared names keep their namespace.
        $this->assertStringContainsString('- **Billing\Invoice** class · domain Billing · uses Payment, Customer [Sales]', $out);
        $this->assertStringContainsString('- **Clock** interface', $out);
    }

    #[Test]
    public function an_app_without_domains_has_no_between_section(): void
    {
        $index = $this->index();
        $index['classes'] = array_map(fn (array $class) => array_replace($class, ['context' => null]), $index['classes']);

        $this->assertStringNotContainsString('Between domains', $this->markdown->render('classes', $index));
    }

    #[Test]
    public function one_class_says_what_it_uses_and_what_uses_it(): void
    {
        $index = $this->index();

        $this->assertSame(implode("\n", [
            '## Billing\Invoice (class)',
            '`App\Billing\Invoice` · domain Billing · app/Invoice.php:5',
            '- uses:',
            '  - Payment: relation line 9',
            '  - Customer: relation line 8; type lines 12, 14 — crosses into Sales',
            '- used by:',
            '  - Payment: relation line 7',
            '  - Customer: relation line 6 — from Sales',
        ])."\n", $this->markdown->class($index['classes'][0], $index));

        $this->assertStringContainsString("- uses: no other class in the app\n- used by: no other class in the app", $this->markdown->class($index['classes'][3], $index));
        $this->assertStringStartsWith('## Clock (interface)', $this->markdown->class($index['classes'][4], $index));
    }

    #[Test]
    public function boundaries_say_when_nothing_is_checked(): void
    {
        $this->assertSame(
            "# Boundaries\n\nNo boundary rules. Add a \"rules\" object to .dissect/domains.json to check a domain.\n",
            $this->markdown->render('boundaries', ['enabled' => true, 'rules' => [], 'violations' => []]),
        );
        $this->assertStringContainsString('switched off', $this->markdown->boundaries(['enabled' => false]));
    }

    #[Test]
    public function boundaries_list_rules_problems_and_each_violation_where_it_is(): void
    {
        $out = $this->markdown->render('boundaries', [
            'enabled' => true,
            'rules' => [
                ['domain' => 'Billing', 'may_use' => ['Identity'], 'must_not_use' => ['Illuminate\Http']],
                ['domain' => 'Sales', 'may_use' => [], 'must_not_use' => []],
            ],
            'violations' => [
                ['domain' => 'Billing', 'file' => 'app/Billing/Invoice.php', 'lines' => [9, 12], 'message' => 'Billing may not use Sales: Invoice → Customer (relation, lines 9, 12).'],
                ['domain' => 'Sales', 'file' => 'app/Sales/Customer.php', 'lines' => [8], 'message' => 'Sales may not use Billing: Customer → Invoice (relation, line 8).'],
            ],
            'problems' => ['rules.Sales.may_use: no domain is called Identiy.'],
            'unchecked' => ['app/Broken.php'],
        ]);

        $this->assertSame(<<<'MD'
            # Boundaries

            2 rules, 2 violations.
            1 file could not be parsed, so it was not checked: app/Broken.php.

            ## Rules
            - **Billing** may use Identity; must not use Illuminate\Http
            - **Sales** may use no other domain

            ## Problems with the rules
            - rules.Sales.may_use: no domain is called Identiy.

            ## Billing (1)
            - Billing may not use Sales: Invoice → Customer (relation, lines 9, 12). `app/Billing/Invoice.php:9`

            ## Sales (1)
            - Sales may not use Billing: Customer → Invoice (relation, line 8). `app/Sales/Customer.php:8`

            MD, $out);
    }

    #[Test]
    public function a_passing_check_says_so_in_one_line(): void
    {
        $this->assertSame("dissect: no boundary rule is broken.\n", $this->markdown->check(['passed' => true, 'rules' => [['domain' => 'Sales']]]));
        $this->assertSame("dissect: no boundary rule is broken (there are no rules).\n", $this->markdown->check(['passed' => true, 'rules' => []]));
    }
}
