<?php

namespace KdrDev\Dissect\Tests\Architecture;

use KdrDev\Dissect\Architecture\References;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each kind of reference, read from code written the way an app writes it.
 */
class ReferencesTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    protected function read(string $code): array
    {
        $statements = (new ParserFactory)->createForHostVersion()->parse("<?php\n".$code);
        $references = new References;
        (new NodeTraverser(new NameResolver, $references))->traverse($statements);

        return $references->declarations;
    }

    /**
     * What the first declaration refers to, as `kind Class` strings.
     *
     * @return array<int, string>
     */
    protected function uses(string $code, int $declaration = 0): array
    {
        $found = [];

        foreach ($this->read($code)[$declaration]['uses'] as $to => $kinds) {
            foreach (array_keys($kinds) as $kind) {
                $found[] = "{$kind} {$to}";
            }
        }

        sort($found);

        return $found;
    }

    #[Test]
    public function a_declaration_says_what_it_is(): void
    {
        $declarations = $this->read(<<<'PHP'
            namespace App;
            abstract class Base {}
            interface Contract {}
            trait Helps {}
            enum Status: string {}
            final class Thing extends Base {}
            PHP);

        $this->assertSame(
            [['App\Base', 'class', true], ['App\Contract', 'interface', true], ['App\Helps', 'trait', false], ['App\Status', 'enum', false], ['App\Thing', 'class', false]],
            array_map(fn (array $d) => [$d['id'], $d['kind'], $d['abstract']], $declarations),
        );
        // Line 1 is the opening tag.
        $this->assertSame([3, 4, 5, 6, 7], array_column($declarations, 'line'));
    }

    #[Test]
    public function what_a_declaration_is_built_from(): void
    {
        $this->assertSame(
            ['extends App\Base', 'implements App\Contracts\Billable', 'implements Countable', 'trait App\Concerns\HasMoney'],
            $this->uses(<<<'PHP'
                namespace App;
                use App\Contracts\Billable;
                class Invoice extends Base implements Billable, \Countable {
                    use Concerns\HasMoney;
                }
                PHP),
        );

        $this->assertSame(['extends App\Readable'], $this->uses('namespace App; interface Writable extends Readable {}'));
        $this->assertSame(['implements App\HasLabel'], $this->uses('namespace App; enum Status implements HasLabel {}'));
    }

    #[Test]
    public function what_a_body_names(): void
    {
        $this->assertSame([
            'attribute App\Attributes\Audited',
            'catch App\Exceptions\Declined',
            'instanceof App\Refund',
            'new App\Receipt',
            'static App\Money',
            'static App\Rates',
            'static App\Status',
            'type App\Customer',
            'type App\Ledger',
            'type App\Line',
            'type App\Note',
            'type App\Total',
            'type Stringable',
        ], $this->uses(<<<'PHP'
            namespace App;
            #[Attributes\Audited]
            class Invoice {
                public const ?Note NOTE = null;
                protected Ledger $ledger;
                public function __construct(private Customer $customer) {}
                public function total(?Line $line, Total|\Stringable $hint): static {
                    try {
                        $rate = Rates::$current;
                        $receipt = new Receipt(Money::of(1));
                        $status = Status::Paid;
                        return $this instanceof Refund ? $this : $this;
                    } catch (Exceptions\Declined $e) {
                        return $this;
                    }
                }
            }
            PHP));
    }

    #[Test]
    public function a_relation_names_the_related_model(): void
    {
        $uses = $this->uses(<<<'PHP'
            namespace App\Models;
            class Invoice extends \Illuminate\Database\Eloquent\Model {
                public function customer() { return $this->belongsTo(Customer::class); }
                public function payments() { return $this->hasMany(Payment::class, 'invoice_id'); }
                // Not its own relation: a builder somewhere else.
                public function notes($other) { return $other->hasMany(Note::class); }
            }
            PHP);

        $this->assertSame(
            ['extends Illuminate\Database\Eloquent\Model', 'relation App\Models\Customer', 'relation App\Models\Payment', 'static App\Models\Note'],
            $uses,
        );
    }

    #[Test]
    public function sending_a_job_or_an_event_is_a_dispatch_or_an_event(): void
    {
        $uses = $this->uses(<<<'PHP'
            namespace App\Http;
            use App\Jobs\{Charge, Refund, Notify, Archive};
            use App\Events\{Paid, Voided};
            use Illuminate\Support\Facades\{Bus, Event};
            class InvoiceController {
                public function store() {
                    Charge::dispatch();
                    dispatch(new Refund);
                    $this->dispatch(new Notify);
                    Bus::dispatch(new Archive);
                    event(new Paid);
                    Event::dispatch(new Voided);
                }
            }
            PHP);

        foreach (['dispatch App\Jobs\Charge', 'dispatch App\Jobs\Refund', 'dispatch App\Jobs\Notify', 'dispatch App\Jobs\Archive', 'event App\Events\Paid', 'event App\Events\Voided'] as $expected) {
            $this->assertContains($expected, $uses);
        }

        // Each name has one kind: a dispatched job is not also "new".
        $this->assertNotContains('new App\Jobs\Refund', $uses);
    }

    #[Test]
    public function a_container_binding_names_both_ends(): void
    {
        $uses = $this->uses(<<<'PHP'
            namespace App\Providers;
            use App\Contracts\{Gateway, Mailer, Clock};
            use App\Services\{StripeGateway, SesMailer, FakeGateway, SystemClock};
            use App\Jobs\Refund;
            class BillingServiceProvider {
                public function register() {
                    $this->app->singleton(Gateway::class, StripeGateway::class);
                    app()->bind(Mailer::class, SesMailer::class);
                    $this->app->when(Refund::class)->needs(Gateway::class)->give(FakeGateway::class);
                    // Not the container: a class constant handed to anything else.
                    $this->registry->bind(Clock::class, SystemClock::class);
                }
            }
            PHP);

        $this->assertSame([
            'binding App\Contracts\Gateway',
            'binding App\Contracts\Mailer',
            'binding App\Jobs\Refund',
            'binding App\Services\FakeGateway',
            'binding App\Services\SesMailer',
            'binding App\Services\StripeGateway',
            'static App\Contracts\Clock',
            'static App\Services\SystemClock',
        ], $uses);
    }

    #[Test]
    public function names_that_follow_nothing_are_left_out(): void
    {
        $this->assertSame([], $this->uses(<<<'PHP'
            namespace App;
            class Invoice {
                public function copy(string $class): static {
                    $a = new self;
                    $b = new static;
                    $c = new $class;
                    $d = self::make();
                    return Invoice::make();
                }
                public static function make(): self { return new self; }
            }
            PHP));
    }

    #[Test]
    public function an_anonymous_class_belongs_to_the_class_it_is_written_in(): void
    {
        $declarations = $this->read(<<<'PHP'
            namespace App;
            class Factory {
                public function make() {
                    return new class extends Base {
                        public function run(Helper $helper) {}
                    };
                }
            }
            PHP);

        $this->assertCount(1, $declarations);
        $this->assertSame(['new App\Base', 'type App\Helper'], $this->uses(<<<'PHP'
            namespace App;
            class Factory {
                public function make() {
                    return new class extends Base {
                        public function run(Helper $helper) {}
                    };
                }
            }
            PHP));
    }

    #[Test]
    public function each_line_a_class_is_named_on_is_kept_once(): void
    {
        $declaration = $this->read(<<<'PHP'
            namespace App;
            class Invoice {
                public function a() { return new Money(1) ?? new Money(2); }
                public function b() { return new Money(3); }
            }
            PHP)[0];

        $this->assertSame(['App\Money' => ['new' => [4, 5]]], $declaration['uses']);
    }

    #[Test]
    public function each_declaration_in_a_file_keeps_its_own_references_and_code_outside_one_has_none(): void
    {
        $declarations = $this->read(<<<'PHP'
            namespace App;
            function helper() { return new Stray; }
            class A { public function make() { return new One; } }
            class B { public function make() { return new Two; } }
            PHP);

        $this->assertSame(['App\One'], array_keys($declarations[0]['uses']));
        $this->assertSame(['App\Two'], array_keys($declarations[1]['uses']));
    }
}
