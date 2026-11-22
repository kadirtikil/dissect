<?php

namespace KdrDev\Dissect\Tests\Changes;

use KdrDev\Dissect\Changes\Diff;
use KdrDev\Dissect\Changes\Snapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DiffTest extends TestCase
{
    /** @return array<string, mixed> */
    protected function snapshot(array $parts = []): array
    {
        return ['version' => Snapshot::VERSION, 'models' => [], 'routes' => [], 'jobs' => [], 'providers' => [], ...$parts];
    }

    /** @return array<string, mixed> */
    protected function invoice(array $overrides = []): array
    {
        return array_replace_recursive([
            'table' => 'invoices',
            'connection' => 'sqlite',
            'context' => 'Billing',
            'columns' => [
                'id' => ['type' => 'integer', 'nullable' => false, 'unique' => true],
                'total' => ['type' => 'integer', 'nullable' => false, 'unique' => false],
            ],
            'relations' => [
                'customer' => ['type' => 'BelongsTo', 'target' => 'App\\Models\\Customer', 'keys' => ['source' => 'customer_id', 'target' => 'id', 'via' => null]],
            ],
        ], $overrides);
    }

    #[Test]
    public function the_same_snapshot_twice_is_no_change(): void
    {
        $snapshot = $this->snapshot(['models' => ['App\\Models\\Invoice' => $this->invoice()]]);

        $this->assertSame([], Diff::between($snapshot, $snapshot));
    }

    #[Test]
    public function a_new_or_gone_item_is_one_change_not_one_per_fact(): void
    {
        $before = $this->snapshot(['models' => ['App\\Models\\Invoice' => $this->invoice()]]);
        $after = $this->snapshot(['models' => ['App\\Models\\Refund' => $this->invoice(['table' => 'refunds'])]]);

        $this->assertSame([
            ['part' => 'models', 'id' => 'App\\Models\\Invoice', 'change' => 'removed', 'facts' => []],
            ['part' => 'models', 'id' => 'App\\Models\\Refund', 'change' => 'added', 'facts' => []],
        ], Diff::between($before, $after));
    }

    #[Test]
    public function a_changed_item_lists_each_fact_with_the_path_to_it(): void
    {
        $before = $this->snapshot(['models' => ['App\\Models\\Invoice' => $this->invoice()]]);
        $invoice = $this->invoice(['columns' => ['total' => ['nullable' => true]]]);
        $invoice['columns']['refunded_at'] = ['type' => 'datetime', 'nullable' => true, 'unique' => false];
        unset($invoice['relations']['customer']);
        $after = $this->snapshot(['models' => ['App\\Models\\Invoice' => $invoice]]);

        [$change] = Diff::between($before, $after);

        $this->assertSame('changed', $change['change']);
        $this->assertSame([
            ['path' => ['columns', 'refunded_at'], 'change' => 'added', 'before' => null, 'after' => ['type' => 'datetime', 'nullable' => true, 'unique' => false]],
            ['path' => ['columns', 'total', 'nullable'], 'change' => 'changed', 'before' => false, 'after' => true],
            ['path' => ['relations', 'customer'], 'change' => 'removed', 'before' => $this->invoice()['relations']['customer'], 'after' => null],
        ], $change['facts']);
    }

    #[Test]
    public function lists_are_compared_as_sets(): void
    {
        $route = fn (array $middleware, array $rules) => ['routes' => ['POST:api/invoices' => [
            'middleware' => $middleware,
            'request' => ['source' => 'form-request', 'fields' => ['total' => ['type' => 'integer', 'required' => true, 'rules' => $rules]]],
        ]]];

        $this->assertSame([], Diff::between(
            $this->snapshot($route(['api', 'auth'], ['required', 'integer'])),
            $this->snapshot($route(['auth', 'api'], ['integer', 'required'])),
        ), 'the order things are listed in is not a change');

        [$change] = Diff::between(
            $this->snapshot($route(['api', 'auth'], ['required', 'integer'])),
            $this->snapshot($route(['api', 'throttle:6,1'], ['required', 'integer', 'min:1'])),
        );

        $this->assertSame([
            ['path' => ['middleware'], 'change' => 'removed', 'before' => 'auth', 'after' => null],
            ['path' => ['middleware'], 'change' => 'added', 'before' => null, 'after' => 'throttle:6,1'],
            ['path' => ['request', 'fields', 'total', 'rules'], 'change' => 'added', 'before' => null, 'after' => 'min:1'],
        ], $change['facts']);
    }

    #[Test]
    public function a_map_left_out_because_it_was_empty_counts_as_empty(): void
    {
        $without = $this->invoice();
        unset($without['relations']);

        // The first relation is a relation added, not "relations" appearing.
        [$change] = Diff::between(
            $this->snapshot(['models' => ['App\\Models\\Invoice' => $without]]),
            $this->snapshot(['models' => ['App\\Models\\Invoice' => $this->invoice()]]),
        );

        $this->assertSame([['path' => ['relations', 'customer'], 'change' => 'added', 'before' => null, 'after' => $this->invoice()['relations']['customer']]], $change['facts']);

        // A route's request fields: a nested map, left out when empty.
        [$change] = Diff::between(
            $this->snapshot(['routes' => ['POST:api/x' => ['request' => ['source' => 'none']]]]),
            $this->snapshot(['routes' => ['POST:api/x' => ['request' => ['source' => 'inline-validate', 'fields' => ['email' => ['type' => 'string', 'required' => true, 'rules' => ['required']]]]]]]),
        );

        $this->assertSame([
            ['path' => ['request', 'fields', 'email'], 'change' => 'added', 'before' => null, 'after' => ['type' => 'string', 'required' => true, 'rules' => ['required']]],
            ['path' => ['request', 'source'], 'change' => 'changed', 'before' => 'none', 'after' => 'inline-validate'],
        ], $change['facts']);
    }

    #[Test]
    public function a_named_thing_that_is_a_plain_value_is_still_added_or_removed(): void
    {
        [$change] = Diff::between(
            $this->snapshot(['providers' => ['App\\Providers\\P' => ['deferred' => false]]]),
            $this->snapshot(['providers' => ['App\\Providers\\P' => ['deferred' => false, 'side_effects' => ['listens' => 2]]]]),
        );

        $this->assertSame([['path' => ['side_effects', 'listens'], 'change' => 'added', 'before' => null, 'after' => 2]], $change['facts']);
    }

    #[Test]
    public function changes_come_part_by_part_then_by_id(): void
    {
        $before = $this->snapshot();
        $after = $this->snapshot([
            'providers' => ['App\\Providers\\A' => ['deferred' => false]],
            'routes' => ['POST:b' => ['name' => null], 'GET:a' => ['name' => null]],
            'models' => ['App\\Models\\Z' => $this->invoice()],
        ]);

        $this->assertSame(
            ['models App\\Models\\Z', 'routes GET:a', 'routes POST:b', 'providers App\\Providers\\A'],
            array_map(fn (array $change) => $change['part'].' '.$change['id'], Diff::between($before, $after)),
        );
    }

    #[Test]
    public function a_snapshot_read_back_from_json_compares_like_the_one_taken(): void
    {
        // json_decode gives [] for both {} and []: nothing must change for it.
        $snapshot = $this->snapshot(['routes' => ['GET:up' => ['middleware' => [], 'request' => ['source' => 'none']]]]);
        $read = json_decode(Snapshot::encode($snapshot), true);

        $this->assertSame([], Diff::between($snapshot, $read));
        $this->assertSame([], Diff::between($read, $snapshot));
    }
}
