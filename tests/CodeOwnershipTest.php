<?php

namespace KdrDev\Dissect\Tests;

use KdrDev\Dissect\Support\CodeOwnership;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Whose code a file is, against a Composer install laid out on disk: one
 * package copied in from a `path` repository, one downloaded as usual, and the
 * framework.
 */
class CodeOwnershipTest extends PhpUnitTestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/dissect-owners-'.uniqid();

        $this->file('vendor/acme/billing/src/Invoice.php');         // copied from packages/billing
        $this->file('vendor/acme/billing-legacy/src/Invoice.php');  // downloaded, and a name prefix of the above
        $this->file('vendor/spatie/thing/src/Thing.php');           // downloaded
        $this->file('vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php');
        $this->file('app/Models/Post.php');

        $this->installed(['packages' => [
            ['name' => 'acme/billing', 'dist' => ['type' => 'path', 'url' => '../packages/billing'], 'install-path' => '../acme/billing'],
            ['name' => 'acme/billing-legacy', 'dist' => ['type' => 'zip'], 'install-path' => '../acme/billing-legacy'],
            ['name' => 'spatie/thing', 'dist' => ['type' => 'zip'], 'install-path' => '../spatie/thing'],
        ]]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    protected function file(string $relative): string
    {
        $path = $this->root.'/'.$relative;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, '<?php');

        return $path;
    }

    protected function installed(array $contents): void
    {
        @mkdir($this->root.'/vendor/composer', 0755, true);
        file_put_contents($this->root.'/vendor/composer/installed.json', json_encode($contents));
    }

    protected function owners(): CodeOwnership
    {
        return new CodeOwnership($this->root.'/vendor/composer');
    }

    #[Test]
    public function a_package_copied_in_from_a_path_repository_is_the_applications(): void
    {
        // The case "under vendor/" got wrong: the application's own module,
        // installed as a copy.
        $this->assertSame('app', $this->owners()->of('Acme\Billing\Invoice', $this->root.'/vendor/acme/billing/src/Invoice.php'));
    }

    #[Test]
    public function a_downloaded_package_is_a_dependency(): void
    {
        $this->assertSame('vendor', $this->owners()->of('Spatie\Thing', $this->root.'/vendor/spatie/thing/src/Thing.php'));

        // Its name starts with the local package's, its directory is not inside it.
        $this->assertSame('vendor', $this->owners()->of('Acme\Legacy\Invoice', $this->root.'/vendor/acme/billing-legacy/src/Invoice.php'));
    }

    #[Test]
    public function the_framework_is_the_framework_by_name_or_by_file(): void
    {
        $this->assertSame('framework', $this->owners()->of('Illuminate\Bus\Dispatcher', $this->root.'/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php'));
        $this->assertSame('framework', $this->owners()->of('\Illuminate\Bus\Dispatcher', null));
        $this->assertSame('framework', $this->owners()->of('Closure', $this->root.'/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php'));
    }

    #[Test]
    public function a_file_outside_vendor_is_the_applications(): void
    {
        $this->assertSame('app', $this->owners()->of('App\Models\Post', $this->root.'/app/Models/Post.php'));
    }

    #[Test]
    public function a_class_with_no_file_is_not_the_applications(): void
    {
        // Eval'd, generated or internal: nothing the application wrote.
        $this->assertSame('vendor', $this->owners()->of('Generated\Thing', false));
        $this->assertSame('vendor', $this->owners()->of(null, null));
    }

    #[Test]
    public function a_symlinked_install_compares_as_the_same_place(): void
    {
        // The default for a path repository. PHP already reports the real
        // file; this pins that the install directory is resolved the same way.
        $this->file('packages/orders/src/Order.php');
        @mkdir($this->root.'/vendor/acme', 0755, true);
        symlink('../../packages/orders', $this->root.'/vendor/acme/orders');

        $this->installed(['packages' => [
            ['name' => 'acme/orders', 'dist' => ['type' => 'path'], 'install-path' => '../acme/orders'],
        ]]);

        $this->assertSame('app', $this->owners()->of('Acme\Orders\Order', $this->root.'/vendor/acme/orders/src/Order.php'));
        $this->assertSame('app', $this->owners()->of('Acme\Orders\Order', $this->root.'/packages/orders/src/Order.php'));
    }

    #[Test]
    public function it_reads_the_list_composer_1_wrote(): void
    {
        $this->installed([
            ['name' => 'acme/billing', 'dist' => ['type' => 'path'], 'install-path' => '../acme/billing'],
        ]);

        $this->assertSame('app', $this->owners()->of('Acme\Billing\Invoice', $this->root.'/vendor/acme/billing/src/Invoice.php'));
    }

    #[Test]
    public function without_an_installed_json_it_falls_back_to_the_location(): void
    {
        unlink($this->root.'/vendor/composer/installed.json');

        $this->assertSame('vendor', $this->owners()->of('Acme\Billing\Invoice', $this->root.'/vendor/acme/billing/src/Invoice.php'));
        $this->assertSame('app', $this->owners()->of('App\Models\Post', $this->root.'/app/Models/Post.php'));
    }
}
