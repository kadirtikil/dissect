<?php

namespace KdrDev\Dissect\Tests\Mcp;

use KdrDev\Dissect\Mcp\DissectServer;
use KdrDev\Dissect\Mcp\Payloads;
use KdrDev\Dissect\Mcp\Tools\Check;
use KdrDev\Dissect\Mcp\Tools\ClassReferences;
use KdrDev\Dissect\Mcp\Tools\Domain;
use KdrDev\Dissect\Mcp\Tools\Model;
use KdrDev\Dissect\Mcp\Tools\Search;
use KdrDev\Dissect\Support\Surfaces;
use KdrDev\Dissect\Tests\Concerns\UsesDomainFixture;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** The tools on an app split into Billing, Sales and Identity. */
class DomainToolsTest extends TestCase
{
    use UsesDomainFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Payloads::class, Payloads::inProcess($this->app->make(Surfaces::class)));
    }

    #[Test]
    public function every_domain_and_how_it_was_found(): void
    {
        DissectServer::tool(Domain::class)
            ->assertOk()
            ->assertSee(['- **Billing** (Domain/ folder, #[Domain] attribute)', '- **Identity** (domains.json, #[Domain] attribute)', '## To check'])
            ->assertDontSee('Workbench\Domains\Domain\Billing\Models\Invoice');
    }

    #[Test]
    public function one_domain_lists_its_classes(): void
    {
        DissectServer::tool(Domain::class, ['name' => 'billing'])
            ->assertOk()
            ->assertSee(['- **Billing**', '  - Workbench\Domains\Domain\Billing\Models\Invoice'])
            ->assertDontSee('**Sales**');
    }

    #[Test]
    public function a_model_says_its_domain_and_where_its_relations_cross(): void
    {
        DissectServer::tool(Model::class, ['name' => 'Invoice'])
            ->assertOk()
            ->assertSee(['· domain Billing', '- customer → Customer (BelongsTo, customer_id → id) — crosses into Sales']);

        DissectServer::tool(Model::class)->assertOk()->assertSee('- Invoice `invoices` · domain Billing');
    }

    #[Test]
    public function search_finds_the_domain_a_class_is_in(): void
    {
        DissectServer::tool(Search::class, ['query' => 'Quote'])
            ->assertOk()
            ->assertSee(['## Domains (dissect_domain)', '- Sales holds ']);
    }

    #[Test]
    public function a_class_says_what_depends_on_it_and_from_which_domain(): void
    {
        DissectServer::tool(ClassReferences::class, ['name' => 'Invoice'])
            ->assertOk()
            ->assertSee([
                '## Invoice (class)',
                '  - Customer: relation line 15 — crosses into Sales',
                '- used by:',
                '  - InvoiceController: static line 12; type line 15',
                '  - Customer: relation line 19 — from Sales',
            ]);
    }

    #[Test]
    public function without_a_name_the_class_tool_starts_with_the_domains(): void
    {
        DissectServer::tool(ClassReferences::class)
            ->assertOk()
            ->assertSee(['# Classes', '## Between domains', '- Billing → Sales: 1 (relation 1)']);
    }

    #[Test]
    public function check_lists_what_breaks_a_rule_and_the_rule(): void
    {
        DissectServer::tool(Check::class)
            ->assertOk()
            ->assertSee([
                '- `app/Domain/Sales/Models/Customer.php:19` Sales may not use Billing: Customer → Invoice (relation, line 19).',
                '- **Sales** may use no other domain',
            ]);
    }

    #[Test]
    public function check_narrows_to_the_files_given(): void
    {
        DissectServer::tool(Check::class, ['files' => ['app/Domain/Sales/Models/Quote.php']])
            ->assertOk()
            ->assertSee('dissect: no boundary rule is broken.')
            ->assertDontSee('Customer.php');
    }
}
