<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use KdrDev\Dissect\Tests\Concerns\UsesDomainFixture;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every row of every surface says which domain it is in, so the page can
 * label and filter by domain without a second request.
 */
class DomainContextTest extends TestCase
{
    use RefreshDatabase, UsesDomainFixture;

    protected const NS = 'Workbench\\Domains\\';

    #[Test]
    public function every_model_carries_its_domain(): void
    {
        $nodes = collect($this->getJson('/dissect/schema.json')->assertOk()->json('nodes'))
            ->pluck('context', 'id')->all();

        $this->assertSame([
            self::NS.'Domain\\Billing\\Models\\Invoice' => 'Billing',
            self::NS.'Domain\\Billing\\Models\\Payment' => 'Billing',
            self::NS.'Domain\\Sales\\Models\\Customer' => 'Sales',
            self::NS.'Domain\\Sales\\Models\\Quote' => 'Sales',
            // By its attribute: the folder says nothing.
            self::NS.'Models\\User' => 'Identity',
        ], $nodes);
    }

    #[Test]
    public function every_route_carries_its_controllers_domain(): void
    {
        $routes = collect($this->getJson('/dissect/routes.json')->assertOk()->json('routes'))
            ->pluck('context', 'uri')->all();

        $this->assertSame('Billing', $routes['api/domains/invoices']);
        $this->assertSame('Billing', $routes['api/domains/invoices/{invoice}/send']);
        $this->assertSame('Sales', $routes['api/domains/quotes']);
        // An invokable controller, put in Identity by domains.json.
        $this->assertSame('Identity', $routes['api/domains/login']);
    }

    #[Test]
    public function a_closure_route_takes_the_domain_of_the_file_it_is_in(): void
    {
        $file = $this->domainFixture().'/app/Domain/Sales/routes-'.uniqid().'.php';
        file_put_contents($file, "<?php\n\\Illuminate\\Support\\Facades\\Route::get('api/domains/ping', fn () => 'pong');\n");

        try {
            require $file;
            $this->app['router']->getRoutes()->refreshNameLookups();
            $routes = collect($this->getJson('/dissect/routes.json')->assertOk()->json('routes'))
                ->pluck('context', 'uri')->all();
        } finally {
            unlink($file);
        }

        $this->assertSame('Sales', $routes['api/domains/ping']);
    }

    #[Test]
    public function every_job_carries_its_domain(): void
    {
        $jobs = collect($this->getJson('/dissect/jobs.json')->assertOk()->json('jobs'))
            ->pluck('context', 'class')->all();

        $this->assertSame([
            self::NS.'Domain\\Sales\\Jobs\\ExpireQuotes' => 'Sales',
            self::NS.'Domain\\Billing\\Jobs\\SendInvoice' => 'Billing',
        ], $jobs);
    }

    #[Test]
    public function a_provider_and_every_class_in_its_tree_carry_their_domain(): void
    {
        $provider = collect($this->getJson('/dissect/providers.json')->assertOk()->json('providers'))
            ->firstWhere('name', 'BillingServiceProvider');

        $this->assertSame('Billing', $provider['context']);

        $nodes = collect($provider['nodes'])->pluck('context', 'id')->all();

        $this->assertSame('Billing', $nodes[self::NS.'Domain\\Billing\\Services\\FakeGateway']);
        // Contracts count too: an interface is in its folder's domain.
        $this->assertSame('Billing', $nodes[self::NS.'Domain\\Billing\\Contracts\\PaymentGateway']);
        // Unassigned code is unassigned, and so is the framework.
        $this->assertNull($nodes[self::NS.'Support\\Clock']);
    }

    #[Test]
    public function switched_off_every_row_says_none(): void
    {
        config()->set('dissect.domains.enabled', false);

        $contexts = collect($this->getJson('/dissect/schema.json')->assertOk()->json('nodes'))->pluck('context');

        $this->assertSame([null], $contexts->unique()->values()->all());
    }
}
