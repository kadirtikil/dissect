<?php

namespace KdrDev\Dissect\Tests\Mcp;

use KdrDev\Dissect\Mcp\Lookup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LookupTest extends TestCase
{
    /** @var array<int, array{id: string, table: string}> */
    protected array $models = [
        ['id' => 'App\Models\Invoice', 'table' => 'invoices'],
        ['id' => 'App\Models\InvoiceLine', 'table' => 'invoice_lines'],
        ['id' => 'App\Models\User', 'table' => 'users'],
    ];

    /** @return array<int, string> */
    protected function find(string $query): array
    {
        return array_column(Lookup::find($this->models, $query, fn (array $m) => [$m['id'], basename(str_replace('\\', '/', $m['id'])), $m['table']]), 'id');
    }

    #[Test]
    public function an_exact_name_wins_over_a_partial_one(): void
    {
        $this->assertSame(['App\Models\Invoice'], $this->find('Invoice'));
        $this->assertSame(['App\Models\Invoice'], $this->find('invoices'));
    }

    #[Test]
    public function without_an_exact_name_every_partial_match_is_found(): void
    {
        $this->assertSame(['App\Models\Invoice', 'App\Models\InvoiceLine'], $this->find('voic'));
    }

    #[Test]
    public function case_and_surrounding_space_do_not_matter(): void
    {
        $this->assertSame(['App\Models\User'], $this->find('  app\models\USER '));
    }

    #[Test]
    public function nothing_and_an_empty_query_find_nothing(): void
    {
        $this->assertSame([], $this->find('Payment'));
        $this->assertSame([], $this->find(''));
    }
}
