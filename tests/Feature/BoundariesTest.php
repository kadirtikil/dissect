<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use KdrDev\Dissect\Tests\Concerns\UsesDomainFixture;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * /boundaries.json on the domain fixture, whose domains.json says Billing may
 * use Sales and must not touch Illuminate\Http, and Sales may use no one.
 */
class BoundariesTest extends TestCase
{
    use UsesDomainFixture;

    #[Test]
    public function the_fixture_breaks_one_rule_where_sales_reaches_into_billing(): void
    {
        $payload = $this->getJson('/dissect/boundaries.json')->assertOk()->json();

        $this->assertSame(['Billing', 'Sales'], array_column($payload['rules'], 'domain'));
        $this->assertSame([], $payload['problems']);
        $this->assertSame([], $payload['unchecked']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $payload['fingerprint']);

        // Billing → Sales is allowed; Sales → Billing is not.
        $this->assertCount(1, $payload['violations']);
        $this->assertSame([
            'rule' => 'may_use',
            'domain' => 'Sales',
            'from' => 'Workbench\Domains\Domain\Sales\Models\Customer',
            'file' => 'app/Domain/Sales/Models/Customer.php',
            'to' => 'Workbench\Domains\Domain\Billing\Models\Invoice',
            'to_domain' => 'Billing',
            'namespace' => null,
            'kind' => 'relation',
            'lines' => [19],
            'message' => 'Sales may not use Billing: Customer → Invoice (relation, line 19).',
        ], $payload['violations'][0]);
    }

    #[Test]
    public function the_fingerprint_poll_reports_it_only_when_asked(): void
    {
        $this->assertArrayNotHasKey('boundaries', $this->getJson('/dissect/fingerprint')->json());

        $polled = $this->getJson('/dissect/fingerprint?boundaries=1')->json('boundaries');

        $this->assertSame($this->getJson('/dissect/boundaries.json')->json('fingerprint'), $polled);
    }

    #[Test]
    public function the_command_line_prints_the_same_violations(): void
    {
        $this->assertSame(0, Artisan::call('dissect:export', ['surface' => 'boundaries', '--format' => 'md']));
        $digest = Artisan::output();

        $this->assertStringContainsString("2 rules, 1 violation.\n", $digest);
        $this->assertStringContainsString("- **Sales** may use no other domain\n", $digest);
        $this->assertStringContainsString(
            '- Sales may not use Billing: Customer → Invoice (relation, line 19). `app/Domain/Sales/Models/Customer.php:19`',
            $digest,
        );
    }
}
