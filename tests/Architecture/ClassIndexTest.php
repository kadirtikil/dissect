<?php

namespace KdrDev\Dissect\Tests\Architecture;

use KdrDev\Dissect\Architecture\ClassIndex;
use KdrDev\Dissect\Architecture\ContextResolver;
use KdrDev\Dissect\Architecture\Conventions;
use KdrDev\Dissect\Architecture\DomainFile;
use KdrDev\Dissect\Architecture\SourceFiles;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ClassIndexTest extends TestCase
{
    use TempTree;

    protected function index(array $paths): ClassIndex
    {
        $root = $this->tree($paths);
        $resolver = new ContextResolver(true, $root, new DomainFile($root.'/.dissect/domains.json', $root), new Conventions);

        return new ClassIndex(new SourceFiles($resolver, ['app']), $resolver);
    }

    /** @return array<int, string> */
    protected function app(): array
    {
        return [
            'app/Domain/Billing/Models/Invoice.php' => <<<'PHP'
                <?php
                namespace App\Domain\Billing\Models;
                use App\Domain\Sales\Models\Customer;
                use Illuminate\Database\Eloquent\Model;
                class Invoice extends Model {
                    public function customer() { return $this->belongsTo(Customer::class); }
                }
                PHP,
            'app/Domain/Sales/Models/Customer.php' => <<<'PHP'
                <?php
                namespace App\Domain\Sales\Models;
                use Illuminate\Database\Eloquent\Model;
                class Customer extends Model {}
                PHP,
            'app/Support/Money.php' => "<?php\nnamespace App\\Support;\nfinal class Money {}\n",
            'app/Support/Broken.php' => "<?php\nclass Broken {\n",
            'app/Support/helpers.php' => "<?php\nfunction money() { return new App\\Support\\Money; }\n",
            'tests/Feature/InvoiceTest.php' => "<?php\nnamespace Tests\\Feature;\nclass InvoiceTest {}\n",
        ];
    }

    #[Test]
    public function every_class_with_its_domain_and_what_it_uses_in_the_app(): void
    {
        $export = $this->index($this->app())->export();
        $classes = array_column($export['classes'], null, 'id');

        $this->assertSame(['App\Domain\Billing\Models\Invoice', 'App\Domain\Sales\Models\Customer', 'App\Support\Money'], array_keys($classes));

        $invoice = $classes['App\Domain\Billing\Models\Invoice'];
        $this->assertSame('Billing', $invoice['context']);
        $this->assertSame('app/Domain/Billing/Models/Invoice.php', $invoice['file']);
        $this->assertSame(5, $invoice['line']);
        // The Model it extends is the framework's, so not listed here.
        $this->assertSame([['to' => 'App\Domain\Sales\Models\Customer', 'kind' => 'relation', 'lines' => [6]]], $invoice['uses']);

        $this->assertNull($classes['App\Support\Money']['context']);
    }

    #[Test]
    public function framework_references_are_kept_for_checks_that_need_them(): void
    {
        $declarations = array_column(array_filter($this->index($this->app())->declarations(), fn (array $d) => $d['id'] !== null), null, 'id');

        $this->assertArrayHasKey('Illuminate\Database\Eloquent\Model', $declarations['App\Domain\Sales\Models\Customer']['uses']);
    }

    #[Test]
    public function a_file_that_does_not_parse_is_reported_and_the_rest_still_read(): void
    {
        $export = $this->index($this->app())->export();

        $this->assertSame(['app/Support/Broken.php'], $export['unreadable']);
        $this->assertCount(3, $export['classes']);
    }

    #[Test]
    public function adding_a_file_moves_the_fingerprint(): void
    {
        $index = $this->index($this->app());
        $before = $index->fingerprint();

        file_put_contents($this->tree.'/app/Support/Rate.php', "<?php\nnamespace App\\Support;\nclass Rate {}\n");

        $this->assertNotSame($before, $index->fingerprint());
    }
}
