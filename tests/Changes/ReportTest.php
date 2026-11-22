<?php

namespace KdrDev\Dissect\Tests\Changes;

use KdrDev\Dissect\Changes\Report;
use KdrDev\Dissect\Changes\Snapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReportTest extends TestCase
{
    /** @return array<string, mixed> */
    protected function before(): array
    {
        return [
            'version' => Snapshot::VERSION,
            'models' => [
                'App\\Models\\Invoice' => [
                    'table' => 'invoices',
                    'context' => 'Billing',
                    'columns' => [
                        'id' => ['type' => 'integer', 'nullable' => false],
                        'legacy_code' => ['type' => 'varchar', 'nullable' => true],
                        'total' => ['type' => 'integer', 'nullable' => false],
                    ],
                    'relations' => [
                        'customer' => ['type' => 'BelongsTo', 'target' => 'App\\Models\\Customer', 'keys' => ['source' => 'customer_id', 'target' => 'id', 'via' => null]],
                    ],
                ],
                'App\\Models\\Customer' => ['table' => 'customers', 'context' => 'Sales'],
            ],
            'routes' => [
                'POST:api/invoices' => [
                    'name' => 'invoices.store',
                    'action' => 'App\\Http\\Controllers\\InvoiceController@store',
                    'middleware' => ['api', 'auth:sanctum'],
                    'request' => ['source' => 'form-request', 'class' => 'App\\Http\\Requests\\StoreInvoice', 'fields' => [
                        'customer_id' => ['type' => 'integer', 'required' => true, 'rules' => ['required', 'integer']],
                        'note' => ['type' => 'string', 'required' => false, 'rules' => ['nullable', 'string']],
                    ]],
                    'response' => ['source' => 'resource', 'class' => 'App\\Http\\Resources\\InvoiceResource', 'status' => 200, 'fields' => [
                        'id' => ['kind' => 'scalar', 'conditional' => false],
                        'total' => ['kind' => 'scalar', 'conditional' => false],
                    ]],
                ],
                'GET|HEAD:api/legacy' => ['name' => null, 'action' => 'closure', 'middleware' => []],
            ],
            'jobs' => [
                'App\\Jobs\\SendInvoice' => [
                    'kind' => 'job',
                    'queue' => null,
                    'retry' => ['tries' => 3, 'timeout' => null],
                    'payload' => ['invoice' => ['type' => 'App\\Models\\Invoice', 'optional' => false, 'variadic' => false, 'default' => null]],
                    'dispatched_by' => ['InvoiceController@send'],
                ],
            ],
            'providers' => [
                'App\\Providers\\BillingServiceProvider' => [
                    'deferred' => false,
                    'edges' => ['App\\Contracts\\Gateway -> App\\Services\\StripeGateway' => ['kind' => 'bind']],
                ],
            ],
        ];
    }

    /** The same app after an agent's afternoon. */
    protected function after(): array
    {
        $after = $this->before();
        $invoice = &$after['models']['App\\Models\\Invoice'];
        unset($invoice['columns']['legacy_code']);
        $invoice['columns']['total']['nullable'] = true;
        $invoice['columns']['total']['type'] = 'decimal';
        $invoice['columns']['refunded_at'] = ['type' => 'datetime', 'nullable' => true];
        $after['models']['App\\Models\\Refund'] = ['table' => 'refunds', 'context' => 'Billing'];

        $store = &$after['routes']['POST:api/invoices'];
        $store['middleware'] = ['api'];
        $store['request']['fields']['currency'] = ['type' => 'string', 'required' => true, 'rules' => ['required', 'string', 'size:3']];
        $store['request']['fields']['customer_id']['rules'][] = 'exists:customers,id';
        $store['response']['status'] = 201;
        unset($store['response']['fields']['total']);
        unset($after['routes']['GET|HEAD:api/legacy']);

        $after['jobs']['App\\Jobs\\SendInvoice']['queue'] = 'mail';
        $after['jobs']['App\\Jobs\\SendInvoice']['retry']['tries'] = null;
        $after['providers']['App\\Providers\\BillingServiceProvider']['edges'] = ['App\\Contracts\\Gateway -> App\\Services\\FakeGateway' => ['kind' => 'bind']];

        return $after;
    }

    /** @return array<int, string> Each change and its facts, as lines. */
    protected function lines(array $report): array
    {
        $lines = [];

        foreach ($report['changes'] as $change) {
            $lines[] = "{$change['text']} [{$change['severity']}]";

            foreach ($change['facts'] as $fact) {
                $lines[] = "    {$fact['text']} [{$fact['severity']}]";
            }
        }

        return $lines;
    }

    #[Test]
    public function each_change_is_judged_and_worded(): void
    {
        $this->assertSame([
            '~ model Invoice [breaking]',
            '    - column legacy_code [breaking]',
            '    + column refunded_at (datetime, nullable) [additive]',
            '    ~ column total: now nullable [risky]',
            '    ~ column total: type integer → decimal [risky]',
            '+ model Refund [additive]',
            '- route GET|HEAD api/legacy [breaking]',
            '~ route POST api/invoices [breaking]',
            '    - middleware auth:sanctum [risky]',
            '    + request field currency (string, required) [breaking]',
            '    + rule exists:customers,id on request field customer_id [risky]',
            '    - response field total [breaking]',
            '    ~ status 200 → 201 [breaking]',
            '~ job SendInvoice [risky]',
            '    ~ queue none → mail [risky]',
            '    ~ tries 3 → none [risky]',
            '~ provider BillingServiceProvider [breaking]',
            '    + binding Gateway → FakeGateway (bind) [additive]',
            '    - binding Gateway → StripeGateway [breaking]',
        ], $this->lines(Report::between($this->before(), $this->after())));
    }

    #[Test]
    public function the_summary_counts_things_to_look_at_not_facts(): void
    {
        $report = Report::between($this->before(), $this->after());

        $this->assertSame(['breaking' => 4, 'risky' => 1, 'additive' => 1], $report['summary']);
    }

    #[Test]
    public function each_change_keeps_what_a_reader_of_the_json_needs(): void
    {
        $change = Report::between($this->before(), $this->after())['changes'][0];

        $this->assertSame('models', $change['part']);
        $this->assertSame('App\\Models\\Invoice', $change['id']);
        $this->assertSame('Invoice', $change['name']);
        $this->assertSame('changed', $change['change']);
        $this->assertSame(['path' => ['columns', 'legacy_code'], 'change' => 'removed', 'before' => ['type' => 'varchar', 'nullable' => true], 'after' => null, 'severity' => 'breaking', 'text' => '- column legacy_code'], $change['facts'][0]);
    }

    #[Test]
    public function two_classes_with_one_short_name_are_told_apart(): void
    {
        $report = Report::between(
            ['models' => []],
            ['models' => ['App\\Billing\\Invoice' => ['table' => 'a'], 'App\\Sales\\Invoice' => ['table' => 'b']]],
        );

        $this->assertSame(['+ model Billing\\Invoice', '+ model Sales\\Invoice'], array_column($report['changes'], 'text'));
    }

    #[Test]
    public function relation_keys_and_side_effects_read_as_words(): void
    {
        $report = Report::between(
            ['models' => ['App\\Models\\Invoice' => ['relations' => ['customer' => ['type' => 'BelongsTo', 'target' => 'App\\Models\\Customer', 'keys' => ['source' => 'customer_id', 'target' => 'id', 'via' => null]]]]],
             'providers' => ['App\\Providers\\P' => ['deferred' => false, 'provides' => ['App\\Contracts\\Gateway']]]],
            ['models' => ['App\\Models\\Invoice' => ['relations' => ['customer' => ['type' => 'BelongsTo', 'target' => 'App\\Models\\Client', 'keys' => ['source' => 'client_id', 'target' => 'id', 'via' => null]]]]],
             'providers' => ['App\\Providers\\P' => ['deferred' => true, 'provides' => [], 'side_effects' => ['listens' => 2]]]],
        );

        $this->assertSame([
            '~ model Invoice',
            '    ~ relation customer: key on this side customer_id → client_id',
            '    ~ relation customer: target Customer → Client',
            '~ provider P',
            '    ~ now deferred',
            '    - provides Gateway',
            '    + side effect listens (2)',
        ], array_map(fn (string $line) => preg_replace('/ \[\w+\]$/', '', $line), $this->lines($report)));
    }

    #[Test]
    public function nothing_changed_is_an_empty_report(): void
    {
        $this->assertSame(['summary' => ['breaking' => 0, 'risky' => 0, 'additive' => 0], 'changes' => []], Report::between($this->before(), $this->before()));
    }
}
