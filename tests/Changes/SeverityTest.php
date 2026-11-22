<?php

namespace KdrDev\Dissect\Tests\Changes;

use KdrDev\Dissect\Changes\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SeverityTest extends TestCase
{
    #[Test]
    public function a_new_item_is_additive_and_a_gone_one_breaking(): void
    {
        $this->assertSame('additive', Severity::ofItem('added'));
        $this->assertSame('breaking', Severity::ofItem('removed'));
    }

    /** @return array<string, array{string, array<int, string>, string, mixed, mixed, string}> */
    public static function facts(): array
    {
        return [
            // The defaults.
            'a column added' => ['models', ['columns', 'refunded_at'], 'added', null, ['type' => 'datetime'], 'additive'],
            'a column removed' => ['models', ['columns', 'legacy'], 'removed', ['type' => 'integer'], null, 'breaking'],
            'a column type changed' => ['models', ['columns', 'total', 'type'], 'changed', 'integer', 'decimal', 'risky'],
            'a relation removed' => ['models', ['relations', 'customer'], 'removed', [], null, 'breaking'],
            'a response field added' => ['routes', ['response', 'fields', 'refunded_at'], 'added', null, ['kind' => 'scalar'], 'additive'],
            'a response field removed' => ['routes', ['response', 'fields', 'total'], 'removed', ['kind' => 'scalar'], null, 'breaking'],
            'a binding removed' => ['providers', ['edges', 'A -> B'], 'removed', ['kind' => 'bind'], null, 'breaking'],
            'a retry setting changed' => ['jobs', ['retry', 'tries'], 'changed', 3, null, 'risky'],

            // Where the default reads it wrong.
            'a table renamed' => ['models', ['table'], 'changed', 'invoices', 'bills', 'breaking'],
            'a column hidden' => ['models', ['columns', 'email', 'hidden'], 'changed', false, true, 'breaking'],
            'a column shown' => ['models', ['columns', 'password', 'hidden'], 'changed', true, false, 'risky'],
            'a route renamed' => ['routes', ['name'], 'changed', 'invoices.store', 'invoices.create', 'breaking'],
            'a route named' => ['routes', ['name'], 'changed', null, 'invoices.store', 'additive'],
            'middleware added' => ['routes', ['middleware'], 'added', null, 'auth:sanctum', 'risky'],
            'middleware taken off' => ['routes', ['middleware'], 'removed', 'auth:sanctum', null, 'risky'],
            'a required parameter' => ['routes', ['parameters', 'invoice'], 'added', null, ['optional' => false], 'breaking'],
            'an optional parameter' => ['routes', ['parameters', 'page'], 'added', null, ['optional' => true], 'additive'],
            'a required request field' => ['routes', ['request', 'fields', 'currency'], 'added', null, ['required' => true], 'breaking'],
            'an optional request field' => ['routes', ['request', 'fields', 'note'], 'added', null, ['required' => false], 'additive'],
            'a request field dropped' => ['routes', ['request', 'fields', 'note'], 'removed', ['required' => false], null, 'risky'],
            'a field now required' => ['routes', ['request', 'fields', 'note', 'required'], 'changed', false, true, 'breaking'],
            'a field now optional' => ['routes', ['request', 'fields', 'note', 'required'], 'changed', true, false, 'additive'],
            'a rule added' => ['routes', ['request', 'fields', 'total', 'rules'], 'added', null, 'min:1', 'risky'],
            'a rule removed' => ['routes', ['request', 'fields', 'total', 'rules'], 'removed', 'min:1', null, 'risky'],
            'a status changed' => ['routes', ['response', 'status'], 'changed', 200, 201, 'breaking'],
            'a response field made conditional' => ['routes', ['response', 'fields', 'author', 'conditional'], 'changed', false, true, 'risky'],
            'a response field made unconditional' => ['routes', ['response', 'fields', 'author', 'conditional'], 'changed', true, false, 'additive'],
            'a required payload argument' => ['jobs', ['payload', 'reason'], 'added', null, ['optional' => false], 'risky'],
            'an optional payload argument' => ['jobs', ['payload', 'reason'], 'added', null, ['optional' => true], 'additive'],
            'a payload argument dropped' => ['jobs', ['payload', 'reason'], 'removed', ['optional' => false], null, 'risky'],
            'dispatched from somewhere new' => ['jobs', ['dispatched_by'], 'added', null, 'RefundController@store', 'additive'],
            'no longer dispatched from somewhere' => ['jobs', ['dispatched_by'], 'removed', 'InvoiceController@send', null, 'risky'],
            'a deferred binding no longer provided' => ['providers', ['provides'], 'removed', 'App\\Contracts\\Gateway', null, 'risky'],
            'a side effect gone' => ['providers', ['side_effects', 'listens'], 'removed', 2, null, 'risky'],
        ];
    }

    #[Test]
    #[DataProvider('facts')]
    public function each_fact_is_judged_for_who_depends_on_it(string $part, array $path, string $change, mixed $before, mixed $after, string $expected): void
    {
        $this->assertSame($expected, Severity::ofFact($part, ['path' => $path, 'change' => $change, 'before' => $before, 'after' => $after]));
    }

    #[Test]
    public function the_worst_of_several_wins(): void
    {
        $this->assertSame('breaking', Severity::worst(['additive', 'breaking', 'risky']));
        $this->assertSame('risky', Severity::worst(['additive', 'risky']));
        $this->assertSame('additive', Severity::worst(['additive']));
        $this->assertSame('additive', Severity::worst([]));
    }
}
