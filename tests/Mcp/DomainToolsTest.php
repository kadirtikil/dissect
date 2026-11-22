<?php

namespace KdrDev\Dissect\Tests\Mcp;

use KdrDev\Dissect\Mcp\DissectServer;
use KdrDev\Dissect\Mcp\Payloads;
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
}
