<?php

namespace KdrDev\Dissect\Tests\Architecture;

use KdrDev\Dissect\Architecture\Context;
use KdrDev\Dissect\Architecture\Conventions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConventionsTest extends TestCase
{
    use TempTree;

    /** @return array<string, array{string[], string[]}> name => [sources, relative roots] */
    protected function detect(array $paths): array
    {
        $root = $this->tree($paths);
        $found = [];

        foreach ((new Conventions)->detect($root) as $context) {
            $found[$context->name] = [
                array_map(fn ($source) => $source->value, $context->sources),
                array_map(fn ($path) => substr($path, strlen($root) + 1), $context->roots),
            ];
        }

        return $found;
    }

    #[Test]
    public function a_plain_laravel_app_has_no_domains(): void
    {
        $this->assertSame([], $this->detect([
            'app/Models/User.php',
            'app/Http/Controllers/Controller.php',
            // A starter kit's login screens, under two layers but not models.
            'app/Http/Controllers/Auth/LoginController.php',
            'app/Http/Requests/Auth/LoginRequest.php',
            'app/Providers/AppServiceProvider.php',
        ]));
    }

    #[Test]
    public function every_folder_under_a_domain_folder_is_a_domain(): void
    {
        $this->assertSame([
            'Billing' => [['domain-folder'], ['app/Domain/Billing']],
            'Sales' => [['domain-folder'], ['src/Domain/Sales']],
            'Shipping' => [['domain-folder'], ['app/Domains/Shipping']],
        ], $this->detect([
            'app/Domain/Billing/Models/Invoice.php',
            'app/Domains/Shipping/',
            'src/Domain/Sales/Actions/CreateQuote.php',
        ]));
    }

    #[Test]
    public function module_packages_name_their_modules(): void
    {
        $this->assertSame([
            'Billing' => [['modules'], ['Modules/Billing']],
            'catalog' => [['app-modules'], ['app-modules/catalog']],
        ], $this->detect([
            'Modules/Billing/app/Models/Invoice.php',
            'app-modules/catalog/src/Models/Product.php',
        ]));
    }

    #[Test]
    public function a_local_package_is_a_folder_with_a_composer_file(): void
    {
        $this->assertSame([
            'billing' => [['packages'], ['packages/acme/billing']],
            'search' => [['packages'], ['packages/search']],
        ], $this->detect([
            'packages/search/composer.json',
            'packages/acme/billing/composer.json',
            // Not a package: no composer.json at either depth.
            'packages/notes/README.md',
        ]));
    }

    #[Test]
    public function two_hexagonal_layers_side_by_side_make_a_context(): void
    {
        $this->assertSame([
            'Billing' => [['hexagonal'], ['src/Billing']],
        ], $this->detect([
            'src/Billing/Domain/Invoice.php',
            'src/Billing/Infrastructure/EloquentInvoices.php',
            // One layer alone could be anything.
            'src/Shared/Domain/Money.php',
        ]));
    }

    #[Test]
    public function a_models_subfolder_with_a_sibling_layer_is_a_domain(): void
    {
        $this->assertSame([
            'Billing' => [['layer-folders'], [
                'app/Models/Billing',
                'app/Http/Controllers/Billing',
                'app/Jobs/Billing',
            ]],
        ], $this->detect([
            'app/Models/Billing/Invoice.php',
            'app/Http/Controllers/Billing/InvoiceController.php',
            'app/Jobs/Billing/SendInvoice.php',
            // Models alone: a folder, not a domain.
            'app/Models/Archive/Old.php',
            // A kind of thing, even beside a sibling.
            'app/Models/Concerns/HasUuid.php',
            'app/Http/Controllers/Concerns/Paginates.php',
        ]));
    }

    #[Test]
    public function one_domain_found_two_ways_is_still_one_domain(): void
    {
        $this->assertSame([
            'Billing' => [['modules', 'domain-folder'], ['Modules/Billing', 'app/Domain/Billing']],
        ], $this->detect([
            'Modules/Billing/',
            'app/Domain/Billing/',
        ]));
    }

    #[Test]
    public function hidden_folders_are_not_domains(): void
    {
        $this->assertSame([], $this->detect(['app/Domain/.git/HEAD']));
    }

    #[Test]
    public function the_longest_root_claims_a_file(): void
    {
        $context = new Context('Billing', [], ['/app/Models', '/app/Models/Billing']);

        $this->assertSame(strlen('/app/Models/Billing'), $context->depth('/app/Models/Billing/Invoice.php'));
        $this->assertNull($context->depth('/app/ModelsExtra/Invoice.php'));
        $this->assertTrue($context->owns('/app/Models'));
    }
}
