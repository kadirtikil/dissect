<?php

namespace KdrDev\Dissect\Tests\Feature;

use KdrDev\Dissect\Architecture\DomainMap;
use KdrDev\Dissect\Tests\Concerns\UsesDomainFixture;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The domain list, read from the domain fixture in workbench/domains.
 */
class DomainsTest extends TestCase
{
    use UsesDomainFixture;

    protected const NS = 'Workbench\\Domains\\';

    /** @return array<string, array<string, mixed>> */
    protected function domains(): array
    {
        $payload = $this->getJson('/dissect/domains.json')->assertOk()->json();

        return collect($payload['domains'])->keyBy('name')->all();
    }

    #[Test]
    public function it_lists_every_domain_and_how_it_was_found(): void
    {
        $domains = $this->domains();

        $this->assertSame(['Billing', 'Identity', 'Sales'], array_keys($domains));
        // TaxRate's attribute names Billing too.
        $this->assertSame(['domain-folder', 'attribute'], $domains['Billing']['sources']);
        $this->assertSame(['domain-folder'], $domains['Sales']['sources']);
        $this->assertSame(['app/Domain/Billing'], $domains['Billing']['roots']);
        $this->assertSame(['config', 'attribute'], $domains['Identity']['sources']);
        $this->assertSame(['app/Http/Controllers/Auth'], $domains['Identity']['roots']);
    }

    #[Test]
    public function every_class_is_listed_under_its_domain(): void
    {
        $domains = $this->domains();

        $this->assertSame([
            self::NS.'Domain\\Billing\\Http\\Controllers\\InvoiceController',
            self::NS.'Domain\\Billing\\Jobs\\SendInvoice',
            self::NS.'Domain\\Billing\\Models\\Invoice',
            self::NS.'Domain\\Billing\\Models\\Payment',
            self::NS.'Domain\\Billing\\Providers\\BillingServiceProvider',
            self::NS.'Domain\\Billing\\Services\\FakeGateway',
            // The attribute wins over the folder, and is reported below.
            self::NS.'Domain\\Sales\\Support\\TaxRate',
        ], $domains['Billing']['classes']);

        $this->assertSame([
            self::NS.'Http\\Controllers\\Auth\\LoginController',
            self::NS.'Models\\User',
        ], $domains['Identity']['classes']);
    }

    #[Test]
    public function a_class_no_domain_claims_is_unassigned_not_guessed(): void
    {
        $this->assertSame([
            self::NS.'Attributes\\Domain',
            self::NS.'Support\\Clock',
        ], $this->getJson('/dissect/domains.json')->json('unassigned'));
    }

    #[Test]
    public function an_attribute_that_disagrees_with_its_folder_is_reported(): void
    {
        $this->assertSame([[
            'kind' => 'override-disagrees',
            'class' => self::NS.'Domain\\Sales\\Support\\TaxRate',
            'file' => 'app/Domain/Sales/Support/TaxRate.php',
            'context' => 'Billing',
            'folder' => 'Sales',
            'message' => "#[Domain('Billing')] on a class in Sales's folder.",
        ]], $this->getJson('/dissect/domains.json')->json('findings'));
    }

    #[Test]
    public function an_attribute_naming_an_unknown_domain_is_reported_and_still_used(): void
    {
        config()->set('dissect.domains.file', $this->domainFixture().'/missing.json');

        $payload = $this->getJson('/dissect/domains.json')->assertOk()->json();
        $identity = collect($payload['domains'])->firstWhere('name', 'Identity');

        $this->assertSame(['attribute'], $identity['sources']);
        $this->assertSame([self::NS.'Models\\User'], $identity['classes']);
        $this->assertContains('unknown-domain', array_column($payload['findings'], 'kind'));
    }

    #[Test]
    public function a_broken_domains_file_is_reported_and_the_folders_still_count(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dissect-domains');
        file_put_contents($file, '{broken');
        config()->set('dissect.domains.file', $file);

        try {
            $payload = $this->getJson('/dissect/domains.json')->assertOk()->json();
        } finally {
            unlink($file);
        }

        $this->assertStringStartsWith('domains.json is not valid JSON', $payload['problems'][0]);
        $this->assertContains('Billing', array_column($payload['domains'], 'name'));
    }

    #[Test]
    public function switched_off_there_are_no_domains(): void
    {
        config()->set('dissect.domains.enabled', false);

        $payload = $this->getJson('/dissect/domains.json')->assertOk()->json();

        $this->assertFalse($payload['enabled']);
        $this->assertSame([], $payload['domains']);
        $this->assertSame([], $payload['unassigned']);
    }

    #[Test]
    public function the_fingerprint_moves_when_domains_json_changes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dissect-domains');
        file_put_contents($file, '{"domains": []}');
        touch($file, time() - 100);
        config()->set('dissect.domains.file', $file);

        try {
            $map = $this->app->make(DomainMap::class);
            $before = $map->fingerprint();
            touch($file, time());
            clearstatcache();
            $after = $map->fingerprint();
            $served = $this->getJson('/dissect/fingerprint?domains=1')->json('domains');
        } finally {
            unlink($file);
        }

        $this->assertSame($after, $served);
        $this->assertNotSame($before, $after);
    }
}
