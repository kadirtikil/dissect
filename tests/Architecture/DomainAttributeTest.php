<?php

namespace KdrDev\Dissect\Tests\Architecture;

use KdrDev\Dissect\Architecture\DomainAttribute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DomainAttributeTest extends TestCase
{
    use TempTree;

    /** @return array<string, array{string, ?string}> */
    public static function files(): array
    {
        return [
            'imported' => ["use App\\Attributes\\Domain;\n\n#[Domain('Identity')]\nclass User {}", 'Identity'],
            'fully qualified' => ["#[\\App\\Attributes\\Domain('Identity')]\nfinal class User {}", 'Identity'],
            'named argument' => ["#[Domain(name: 'Identity')]\nclass User {}", 'Identity'],
            'double quotes' => ["#[Domain(\"Identity\")]\nclass User {}", 'Identity'],
            'one of several' => ["#[Fillable(['a']), Domain('Identity')]\n#[Other]\nclass User {}", 'Identity'],
            'on an enum' => ["#[Domain('Billing')]\nenum Status: string {}", 'Billing'],
            'another attribute' => ["#[DomainEvent('Identity')]\nclass User {}", null],
            'on a method only' => ["class User {\n    #[Domain('Identity')]\n    public function x() {}\n}", null],
            'a computed name' => ["#[Domain(self::NAME)]\nclass User {}", null],
            'an empty name' => ["#[Domain('  ')]\nclass User {}", null],
            'nested in an argument' => ["#[Group([new Domain('Identity')])]\nclass User {}", null],
            'mentioned in a comment' => ["// #[Domain('Identity')]\nclass User {}", null],
            'none' => ['class User {}', null],
            // A file mid-edit still says what it means.
            'half written' => ["#[Domain('Identity'\nclass {", 'Identity'],
            'cut off' => ['#[Domain(', null],
        ];
    }

    #[Test]
    #[DataProvider('files')]
    public function it_reads_the_class_level_attribute(string $body, ?string $expected): void
    {
        $root = $this->tree(['User.php' => "<?php\n\nnamespace App\\Models;\n\n{$body}\n"]);

        $this->assertSame($expected, DomainAttribute::in($root.'/User.php'));
    }

    #[Test]
    public function a_missing_file_has_no_domain(): void
    {
        $this->assertNull(DomainAttribute::in('/does/not/exist.php'));
    }
}
