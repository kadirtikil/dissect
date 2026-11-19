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
    public function switched_off_every_row_says_none(): void
    {
        config()->set('dissect.domains.enabled', false);

        $contexts = collect($this->getJson('/dissect/schema.json')->assertOk()->json('nodes'))->pluck('context');

        $this->assertSame([null], $contexts->unique()->values()->all());
    }
}
