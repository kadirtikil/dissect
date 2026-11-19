<?php

namespace KdrDev\Dissect\Tests;

use KdrDev\Dissect\Routes\ModelLinker;
use KdrDev\Dissect\SchemaExporter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * The join between a class name in somebody's code and a node on the graph.
 *
 * A wrong answer here draws a confident link to the wrong model, which is
 * worse than no link: these pin down when it must say nothing.
 */
class ModelLinkerTest extends PhpUnitTestCase
{
    /** @param  array<string, string>  $tables  class => table */
    protected function linker(array $tables): ModelLinker
    {
        $models = $this->createStub(SchemaExporter::class);
        $models->method('tables')->willReturn($tables);

        return new ModelLinker($models);
    }

    #[Test]
    public function a_model_is_addressed_by_its_full_class_name(): void
    {
        $linker = $this->linker(['App\Models\Author' => 'authors']);

        $this->assertSame('App\Models\Author', $linker->forClass('App\Models\Author'));
        $this->assertSame('App\Models\Author', $linker->forClass('\App\Models\Author'));
        $this->assertSame('App\Models\Author', $linker->forTable('authors'));
        $this->assertTrue($linker->knows('App\Models\Author'));
        $this->assertFalse($linker->knows('Author'));
    }

    #[Test]
    public function a_class_from_another_namespace_is_not_the_model_that_shares_its_name(): void
    {
        // A DTO named after the model it describes. It used to link anyway.
        $linker = $this->linker(['App\Models\Invoice' => 'invoices']);

        $this->assertNull($linker->forClass('App\Billing\Data\Invoice'));
    }

    #[Test]
    public function a_bare_name_resolves_when_one_model_has_it(): void
    {
        $linker = $this->linker(['App\Models\Author' => 'authors']);

        $this->assertSame('App\Models\Author', $linker->forClass('Author'));
        $this->assertNull($linker->forClass('Publisher'));
    }

    #[Test]
    public function a_bare_name_two_models_share_resolves_to_neither(): void
    {
        $linker = $this->linker([
            'App\Billing\Invoice' => 'billing_invoices',
            'App\Sales\Invoice' => 'sales_invoices',
        ]);

        $this->assertNull($linker->forClass('Invoice'));

        // Both are still nodes of their own, each reachable by its full name.
        $this->assertSame('App\Billing\Invoice', $linker->forClass('App\Billing\Invoice'));
        $this->assertSame('App\Sales\Invoice', $linker->forClass('App\Sales\Invoice'));
        $this->assertSame('App\Sales\Invoice', $linker->forTable('sales_invoices'));
    }
}
