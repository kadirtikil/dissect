<?php

namespace KdrDev\Dissect\Tests\Feature;

use KdrDev\Dissect\ProviderTree\ProviderReader;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\BindingServiceProvider;
use Workbench\App\Providers\ContextualServiceProvider;
use Workbench\App\Providers\DeferredSearchServiceProvider;
use Workbench\App\Providers\OpaqueServiceProvider;
use Workbench\App\Providers\PropertyBindingServiceProvider;
use Workbench\App\Providers\RegistersSiblingServiceProvider;
use Workbench\App\Providers\ResolvingServiceProvider;
use Workbench\App\Providers\SideEffectServiceProvider;
use Workbench\App\Providers\SiblingServiceProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * The provider list.
 *
 * Asserted against the fixture providers in `workbench/app/Providers`, which
 * exist one per construct rather than one per shape of application: the four
 * plain container calls, a contextual binding, a provider registering another,
 * a deferred one, bindings declared as properties, a provider that only
 * resolves, one that is all side effects, and one written so that none of it
 * can be read statically.
 *
 * Two of the files in that directory are there to be left out.
 */
class ProvidersTest extends TestCase
{
    #[Test]
    public function it_finds_the_fixture_providers(): void
    {
        $providers = $this->providers();

        foreach ([
            BindingServiceProvider::class,
            ContextualServiceProvider::class,
            DeferredSearchServiceProvider::class,
            OpaqueServiceProvider::class,
            PropertyBindingServiceProvider::class,
            RegistersSiblingServiceProvider::class,
            ResolvingServiceProvider::class,
            SiblingServiceProvider::class,
            SideEffectServiceProvider::class,
            WorkbenchServiceProvider::class,
        ] as $provider) {
            $this->assertArrayHasKey($provider, $providers);
        }
    }

    #[Test]
    public function it_skips_an_abstract_base_provider(): void
    {
        // Real code, and a ServiceProvider subclass by every check but the one
        // that matters: it can never be registered.
        $this->assertArrayNotHasKey(
            'Workbench\App\Providers\AbstractDomainServiceProvider',
            $this->providers(),
        );
    }

    #[Test]
    public function it_skips_a_plain_class_kept_beside_the_providers(): void
    {
        // Nothing stops an application keeping a helper in that directory, and
        // a scan that reported every .php file would call this one a provider.
        $this->assertArrayNotHasKey(
            'Workbench\App\Providers\ProviderSupport',
            $this->providers(),
        );
    }

    #[Test]
    public function it_reports_the_file_each_provider_was_declared_in(): void
    {
        // Ends with, not equals: base_path() here is Testbench's skeleton, so
        // the fixtures sit outside the application root and there is nothing to
        // make them relative to. In an application they come back as
        // `app/Providers/Foo.php`.
        $this->assertStringEndsWith(
            'workbench/app/Providers/BindingServiceProvider.php',
            $this->providers()[BindingServiceProvider::class],
        );
    }

    #[Test]
    public function it_sorts_by_class_name(): void
    {
        $providers = array_keys($this->providers());
        $sorted = $providers;
        sort($sorted);

        $this->assertSame($sorted, $providers);
    }

    #[Test]
    public function it_reads_nothing_from_a_directory_that_is_not_there(): void
    {
        // Under Workbench base_path() is the throwaway skeleton, and a real
        // application may simply not have the folder. Neither is an error.
        $this->assertSame([], (new ProviderReader([__DIR__.'/../../workbench/app/Nowhere']))->read());
    }

    #[Test]
    public function it_reads_nothing_when_no_paths_are_configured(): void
    {
        // Finder throws on in([]), so the empty case must never reach it.
        $this->assertSame([], (new ProviderReader([]))->read());
    }

    /** @return array<class-string, string> */
    protected function providers(): array
    {
        return (new ProviderReader)->read();
    }
}
