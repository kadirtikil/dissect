<?php

namespace KdrDev\Dissect\Tests\Support;

use KdrDev\Dissect\Support\ClassNames;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The rule resources/js/lib/classNames.spec.ts holds the page to, so both say the same. */
class ClassNamesTest extends TestCase
{
    #[Test]
    public function a_class_alone_is_its_base_name(): void
    {
        $this->assertSame(
            ['App\Models\Post' => 'Post', 'App\Models\User' => 'User'],
            ClassNames::short(['App\Models\Post', 'App\Models\User']),
        );
    }

    #[Test]
    public function two_classes_that_share_a_name_keep_what_tells_them_apart(): void
    {
        $this->assertSame(
            [
                'App\Billing\Invoice' => 'Billing\Invoice',
                'App\Sales\Invoice' => 'Sales\Invoice',
                'App\Models\Post' => 'Post',
            ],
            ClassNames::short(['App\Billing\Invoice', 'App\Sales\Invoice', 'App\Models\Post']),
        );
    }

    #[Test]
    public function only_the_colliding_names_grow(): void
    {
        $names = ClassNames::short([
            'Billing\Models\Invoice',
            'Sales\Models\Invoice',
            'Sales\Models\Order',
        ]);

        $this->assertSame('Billing\Models\Invoice', $names['Billing\Models\Invoice']);
        $this->assertSame('Sales\Models\Invoice', $names['Sales\Models\Invoice']);
        $this->assertSame('Order', $names['Sales\Models\Order']);
    }

    #[Test]
    public function repeats_and_leading_slashes_count_once(): void
    {
        $this->assertSame(['App\Post' => 'Post'], ClassNames::short(['App\Post', '\App\Post', 'App\Post']));
    }

    #[Test]
    public function a_name_that_is_a_suffix_of_another_stops_at_its_own_length(): void
    {
        $this->assertSame(
            ['Invoice' => 'Invoice', 'App\Invoice' => 'App\Invoice'],
            ClassNames::short(['Invoice', 'App\Invoice']),
        );
    }

    #[Test]
    public function the_base_name_is_the_last_segment(): void
    {
        $this->assertSame('Invoice', ClassNames::base('\App\Billing\Invoice'));
        $this->assertSame('search.batch', ClassNames::base('search.batch'));
    }
}
