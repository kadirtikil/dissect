<?php

namespace KdrDev\Dissect\Tests\Architecture;

use KdrDev\Dissect\Architecture\ContextResolver;
use KdrDev\Dissect\Architecture\Conventions;
use KdrDev\Dissect\Architecture\DomainFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContextResolverTest extends TestCase
{
    use TempTree;

    protected function resolver(array $paths, ?array $file = null, bool $enabled = true): ContextResolver
    {
        if ($file !== null) {
            $paths['.dissect/domains.json'] = json_encode($file);
        }

        $root = $this->tree($paths);

        return new ContextResolver($enabled, $root, new DomainFile($root.'/.dissect/domains.json', $root), new Conventions);
    }

    protected function at(string $path): string
    {
        return $this->tree.'/'.$path;
    }

    #[Test]
    public function a_folder_convention_assigns_the_files_under_it(): void
    {
        $resolver = $this->resolver(['app/Domain/Billing/Models/Invoice.php', 'app/Models/User.php']);

        $this->assertSame('Billing', $resolver->forFile($this->at('app/Domain/Billing/Models/Invoice.php')));
        $this->assertNull($resolver->forFile($this->at('app/Models/User.php')));
        $this->assertSame(['Billing'], array_keys($resolver->contexts()));
    }

    #[Test]
    public function the_file_beats_a_convention(): void
    {
        $resolver = $this->resolver(
            ['app/Domain/Billing/Models/User.php', 'app/Domain/Identity/'],
            ['domains' => [['name' => 'Identity', 'paths' => ['app/Domain/Billing/Models/User.php']]]],
        );

        $this->assertSame('Identity', $resolver->forFile($this->at('app/Domain/Billing/Models/User.php')));
    }

    #[Test]
    public function an_attribute_beats_the_file_and_the_folder(): void
    {
        $resolver = $this->resolver(
            ['app/Domain/Billing/Models/User.php' => "<?php\n#[Domain('Identity')]\nclass User {}"],
            ['domains' => [['name' => 'Sales', 'paths' => ['app/Domain/Billing/Models']]]],
        );

        $file = $this->at('app/Domain/Billing/Models/User.php');

        $this->assertSame('Identity', $resolver->forFile($file));
        $this->assertSame('Sales', $resolver->fromFolder($file));
    }

    #[Test]
    public function the_longest_configured_root_wins(): void
    {
        $resolver = $this->resolver(['app/Models/Billing/Invoice.php'], ['domains' => [
            ['name' => 'Core', 'paths' => ['app/Models']],
            ['name' => 'Billing', 'paths' => ['app/Models/Billing']],
        ]]);

        $this->assertSame('Billing', $resolver->forFile($this->at('app/Models/Billing/Invoice.php')));
    }

    #[Test]
    public function a_domain_named_both_ways_keeps_both_homes(): void
    {
        $resolver = $this->resolver(
            ['app/Domain/Billing/', 'legacy/billing/Old.php'],
            ['domains' => [['name' => 'Billing', 'paths' => ['legacy/billing']]]],
        );

        $billing = $resolver->contexts()['Billing'];

        $this->assertSame(['config', 'domain-folder'], array_map(fn ($s) => $s->value, $billing->sources));
        $this->assertSame('Billing', $resolver->forFile($this->at('legacy/billing/Old.php')));
    }

    #[Test]
    public function the_file_can_turn_conventions_off(): void
    {
        $resolver = $this->resolver(['app/Domain/Billing/Invoice.php'], ['conventions' => false]);

        $this->assertSame([], $resolver->contexts());
        $this->assertNull($resolver->forFile($this->at('app/Domain/Billing/Invoice.php')));
    }

    #[Test]
    public function switched_off_it_answers_nothing(): void
    {
        $resolver = $this->resolver(
            ['app/Domain/Billing/Invoice.php' => "<?php\n#[Domain('Billing')]\nclass Invoice {}"],
            ['domains' => 'broken'],
            enabled: false,
        );

        $this->assertSame([], $resolver->contexts());
        $this->assertSame([], $resolver->problems());
        $this->assertNull($resolver->forFile($this->at('app/Domain/Billing/Invoice.php')));
        $this->assertNull($resolver->forClass(self::class));
    }

    #[Test]
    public function a_class_is_answered_by_the_file_it_was_declared_in(): void
    {
        $class = 'DissectResolverFixture'.uniqid();
        $resolver = $this->resolver(["app/Domain/Billing/{$class}.php" => "<?php\nclass {$class} {}"]);
        require $this->at("app/Domain/Billing/{$class}.php");

        $this->assertSame('Billing', $resolver->forClass($class));
        $this->assertNull($resolver->forClass('Not\\A\\Class'));
        $this->assertNull($resolver->forClass(null));
    }
}
