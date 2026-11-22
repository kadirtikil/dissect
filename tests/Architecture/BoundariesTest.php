<?php

namespace KdrDev\Dissect\Tests\Architecture;

use KdrDev\Dissect\Architecture\Boundaries;
use KdrDev\Dissect\Architecture\ClassIndex;
use KdrDev\Dissect\Architecture\ContextResolver;
use KdrDev\Dissect\Architecture\Conventions;
use KdrDev\Dissect\Architecture\DomainFile;
use KdrDev\Dissect\Architecture\SourceFiles;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BoundariesTest extends TestCase
{
    use TempTree;

    /** @param  array<string, mixed>|null  $rules */
    protected function boundaries(?array $rules, array $extra = [], bool $enabled = true): Boundaries
    {
        $files = $this->app() + $extra;

        if ($rules !== null) {
            $files['.dissect/domains.json'] = json_encode(['version' => 1, 'rules' => $rules]);
        }

        $root = $this->tree($files);
        $file = new DomainFile($root.'/.dissect/domains.json', $root);
        $resolver = new ContextResolver($enabled, $root, $file, new Conventions);
        $sources = new SourceFiles($resolver, ['app']);

        return new Boundaries(new ClassIndex($sources, $resolver), $file, $resolver, $sources);
    }

    /** @return array<string, string> */
    protected function app(): array
    {
        return [
            'app/Domain/Billing/Models/Invoice.php' => <<<'PHP'
                <?php
                namespace App\Domain\Billing\Models;

                use App\Domain\Sales\Models\Customer;
                use App\Support\Clock;
                use Illuminate\Database\Eloquent\Model;

                class Invoice extends Model {
                    public function customer() { return $this->belongsTo(Customer::class); }
                    public function due(): \DateTimeInterface { return Clock::now(); }
                }
                PHP,
            'app/Domain/Billing/Http/InvoiceController.php' => <<<'PHP'
                <?php
                namespace App\Domain\Billing\Http;

                use App\Domain\Billing\Models\Invoice;
                use Illuminate\Http\Request;

                class InvoiceController {
                    public function show(Request $request, Invoice $invoice) {}
                }
                PHP,
            'app/Domain/Sales/Models/Customer.php' => <<<'PHP'
                <?php
                namespace App\Domain\Sales\Models;

                use App\Domain\Billing\Models\Invoice;
                use Illuminate\Database\Eloquent\Model;

                class Customer extends Model {
                    public function invoices() { return $this->hasMany(Invoice::class); }
                }
                PHP,
            'app/Domain/Identity/User.php' => "<?php\nnamespace App\\Domain\\Identity;\nclass User {}\n",
            'app/Support/Clock.php' => "<?php\nnamespace App\\Support;\nfinal class Clock { public static function now() {} }\n",
        ];
    }

    #[Test]
    public function no_rules_means_nothing_is_checked(): void
    {
        $export = $this->boundaries(null)->export();

        $this->assertSame([], $export['rules']);
        $this->assertSame([], $export['violations']);
        $this->assertSame([], $export['problems']);
    }

    #[Test]
    public function a_domain_using_one_outside_its_allow_list_is_a_violation(): void
    {
        $export = $this->boundaries(['Billing' => ['may_use' => ['Identity']]])->export();

        $this->assertCount(1, $export['violations']);
        $this->assertSame([
            'rule' => 'may_use',
            'domain' => 'Billing',
            'from' => 'App\Domain\Billing\Models\Invoice',
            'file' => 'app/Domain/Billing/Models/Invoice.php',
            'to' => 'App\Domain\Sales\Models\Customer',
            'to_domain' => 'Sales',
            'namespace' => null,
            'kind' => 'relation',
            'lines' => [9],
            'message' => 'Billing may not use Sales: Invoice → Customer (relation, line 9).',
        ], $export['violations'][0]);

        // Its own domain is always allowed, and a class in no domain (Clock)
        // is no domain's boundary. Sales has no rule, so it is not checked.
        $this->assertSame(['Billing'], array_column($export['rules'], 'domain'));
    }

    #[Test]
    public function an_allowed_domain_and_an_unrestricted_one_are_not_violations(): void
    {
        $this->assertSame([], $this->boundaries(['Billing' => ['may_use' => ['Sales']]])->export()['violations']);
        $this->assertSame([], $this->boundaries(['Billing' => ['must_not_use' => []]])->export()['violations']);
    }

    #[Test]
    public function an_empty_allow_list_forbids_every_other_domain(): void
    {
        $violations = $this->boundaries(['Sales' => ['may_use' => []]])->export()['violations'];

        $this->assertSame(['Sales may not use Billing: Customer → Invoice (relation, line 8).'], array_column($violations, 'message'));
    }

    #[Test]
    public function a_forbidden_namespace_is_checked_against_framework_classes_too(): void
    {
        $violations = $this->boundaries(['Billing' => ['must_not_use' => ['Illuminate\Http', 'App\Support']]])->export()['violations'];

        $this->assertSame([
            'Billing must not use Illuminate\Http: InvoiceController → Request (type, line 8).',
            'Billing must not use App\Support: Invoice → Clock (static, line 10).',
        ], array_column($violations, 'message'));
        $this->assertSame(['Illuminate\Http', 'App\Support'], array_column($violations, 'namespace'));
        $this->assertSame([null, null], array_column($violations, 'to_domain'));
    }

    #[Test]
    public function a_rule_naming_a_domain_nothing_defines_is_a_problem(): void
    {
        $export = $this->boundaries([
            'Biling' => ['may_use' => []],
            'Sales' => ['may_use' => ['Billing', 'Identiy']],
        ])->export();

        $this->assertSame([
            'rules.Biling: no domain is called Biling.',
            'rules.Sales.may_use: no domain is called Identiy.',
        ], $export['problems']);
    }

    #[Test]
    public function a_file_that_does_not_parse_is_listed_as_unchecked(): void
    {
        $export = $this->boundaries(['Billing' => ['may_use' => []]], [
            'app/Domain/Billing/Broken.php' => "<?php\nclass Broken {\n",
        ])->export();

        $this->assertSame(['app/Domain/Billing/Broken.php'], $export['unchecked']);
    }

    #[Test]
    public function domains_switched_off_means_no_rules(): void
    {
        $export = $this->boundaries(['Billing' => ['may_use' => []]], enabled: false)->export();

        $this->assertFalse($export['enabled']);
        $this->assertSame([], $export['rules']);
        $this->assertSame([], $export['violations']);
    }

    #[Test]
    public function the_fingerprint_moves_with_the_rules(): void
    {
        $boundaries = $this->boundaries(['Billing' => ['may_use' => []]]);
        $before = $boundaries->fingerprint();

        touch($this->tree.'/.dissect/domains.json', time() + 5);
        clearstatcache();

        $this->assertNotSame($before, $boundaries->fingerprint());
    }
}
