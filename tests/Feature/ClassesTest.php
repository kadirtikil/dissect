<?php

namespace KdrDev\Dissect\Tests\Feature;

use KdrDev\Dissect\Tests\Concerns\UsesDomainFixture;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * /classes.json on the domain fixture: every class, its domain, and the app
 * classes it uses.
 */
class ClassesTest extends TestCase
{
    use UsesDomainFixture;

    protected const NS = 'Workbench\\Domains\\Domain\\';

    /** @return array<string, array<string, mixed>> */
    protected function classes(): array
    {
        return array_column($this->getJson('/dissect/classes.json')->assertOk()->json('classes'), null, 'id');
    }

    #[Test]
    public function every_class_carries_its_domain_like_the_domain_list_says(): void
    {
        $classes = $this->classes();
        $domains = $this->getJson('/dissect/domains.json')->json('domains');

        foreach ($domains as $domain) {
            foreach ($domain['classes'] as $class) {
                $this->assertSame($domain['name'], $classes[$class]['context'] ?? null, $class);
            }
        }

        // Interfaces are indexed too, which the domain list does not count.
        $this->assertSame('interface', $classes[self::NS.'Billing\\Contracts\\PaymentGateway']['kind']);
    }

    #[Test]
    public function a_relation_into_another_domain_is_a_reference_between_them(): void
    {
        $invoice = $this->classes()[self::NS.'Billing\\Models\\Invoice'];

        $this->assertContains(
            ['to' => self::NS.'Sales\\Models\\Customer', 'kind' => 'relation', 'lines' => [15]],
            $invoice['uses'],
        );
    }

    #[Test]
    public function dispatches_and_bindings_are_told_apart_from_plain_mentions(): void
    {
        $classes = $this->classes();
        $kinds = fn (string $class) => array_column($classes[self::NS.$class]['uses'], 'kind', 'to');

        $this->assertSame('dispatch', $kinds('Billing\\Http\\Controllers\\InvoiceController')[self::NS.'Billing\\Jobs\\SendInvoice']);
        $this->assertSame('binding', $kinds('Billing\\Providers\\BillingServiceProvider')[self::NS.'Billing\\Contracts\\PaymentGateway']);
    }

    #[Test]
    public function the_page_can_poll_for_a_change(): void
    {
        $served = $this->getJson('/dissect/classes.json')->json('fingerprint');

        $this->assertNotEmpty($served);
        $this->assertSame($served, $this->getJson('/dissect/fingerprint?classes=1')->json('classes'));
        $this->assertArrayNotHasKey('classes', $this->getJson('/dissect/fingerprint')->json());
    }
}
