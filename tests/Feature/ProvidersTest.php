<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use KdrDev\Dissect\ProviderTree\DependencyResolver;
use KdrDev\Dissect\ProviderTree\ProviderInspector;
use KdrDev\Dissect\ProviderTree\ProviderReader;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Contracts\ReportRenderer;
use Workbench\App\Contracts\SearchIndex;
use Workbench\App\Contracts\Transcoder;
use Workbench\App\Providers\ArchiveServiceProvider;
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
use Workbench\App\Services\ArchiveIndex;
use Workbench\App\Services\ArchiveStorage;
use Workbench\App\Services\DatabaseSearchIndex;
use Workbench\App\Services\FfmpegTranscoder;
use Workbench\App\Services\HtmlReportRenderer;
use Workbench\App\Services\NullSearchIndex;
use Workbench\App\Services\PdfReportRenderer;
use Workbench\App\Services\ReportArchive;
use Workbench\App\Services\ReportMailer;

/**
 * The provider list.
 *
 * Asserted against the fixture providers in `workbench/app/Providers`, which
 * exist one per construct rather than one per shape of application: the four
 * plain container calls, a contextual binding, a provider registering another,
 * a deferred one, bindings declared as properties, a provider that only
 * resolves, one that is all side effects, one written so that none of it can
 * be read statically, and one whose single binding opens onto a constructor
 * chain that reaches the framework and loops back on itself.
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
            ArchiveServiceProvider::class,
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

    #[Test]
    public function it_reads_the_four_plain_container_calls_as_contract_and_concrete(): void
    {
        $this->assertEdges(BindingServiceProvider::class, [
            BindingServiceProvider::class.'->'.SearchIndex::class.':bind' => 'certain',
            SearchIndex::class.'->'.DatabaseSearchIndex::class.':bind' => 'certain',
            BindingServiceProvider::class.'->'.Transcoder::class.':singleton' => 'certain',
            Transcoder::class.'->'.FfmpegTranscoder::class.':singleton' => 'certain',
            BindingServiceProvider::class.'->workbench.scoped-index:scoped' => 'certain',
            'workbench.scoped-index->'.DatabaseSearchIndex::class.':scoped' => 'certain',
            // instance() hands over an object; the `new` written there names it.
            BindingServiceProvider::class.'->workbench.transcoder:instance' => 'certain',
            'workbench.transcoder->'.FfmpegTranscoder::class.':instance' => 'certain',
        ]);

        $nodes = $this->nodes(BindingServiceProvider::class);

        $this->assertSame('contract', $nodes[SearchIndex::class]['kind']);
        $this->assertSame('concrete', $nodes[DatabaseSearchIndex::class]['kind']);
        $this->assertSame(2, $nodes[DatabaseSearchIndex::class]['depth']);

        // A container key is nobody's class, and saying it is the app's would be a guess.
        $this->assertSame('none', $nodes['workbench.scoped-index']['origin']);
        $this->assertNull($nodes['workbench.scoped-index']['class']);
    }

    #[Test]
    public function it_puts_the_provider_first_as_the_root(): void
    {
        $root = $this->describe(BindingServiceProvider::class)['nodes'][0];

        $this->assertSame(BindingServiceProvider::class, $root['id']);
        $this->assertSame('provider', $root['kind']);
        $this->assertSame(0, $root['depth']);
        $this->assertSame('The four plain container calls, each with a literal class-string.', $root['summary']);
        $this->assertStringEndsWith('workbench/app/Providers/BindingServiceProvider.php', $root['file']);
    }

    #[Test]
    public function it_reads_a_contextual_binding_beside_the_default_it_overrides(): void
    {
        $this->assertEdges(ContextualServiceProvider::class, [
            ContextualServiceProvider::class.'->'.ReportRenderer::class.':bind' => 'certain',
            ReportRenderer::class.'->'.HtmlReportRenderer::class.':bind' => 'certain',
            ContextualServiceProvider::class.'->'.ReportRenderer::class.':contextual@'.ReportMailer::class => 'certain',
            ReportRenderer::class.'->'.PdfReportRenderer::class.':contextual@'.ReportMailer::class => 'certain',
        ]);
    }

    #[Test]
    public function it_reads_what_a_deferred_provider_promises(): void
    {
        $description = $this->describe(DeferredSearchServiceProvider::class);

        $this->assertTrue($description['deferred']);
        $this->assertSame(['workbench.null-index'], $description['provides']);
        $this->assertFalse($this->describe(BindingServiceProvider::class)['deferred']);
    }

    #[Test]
    public function it_reads_bindings_declared_as_properties(): void
    {
        // register() is empty; the framework registers these on the provider's behalf.
        $this->assertEdges(PropertyBindingServiceProvider::class, [
            PropertyBindingServiceProvider::class.'->'.SearchIndex::class.':bind' => 'certain',
            SearchIndex::class.'->'.DatabaseSearchIndex::class.':bind' => 'certain',
            PropertyBindingServiceProvider::class.'->'.Transcoder::class.':singleton' => 'certain',
            Transcoder::class.'->'.FfmpegTranscoder::class.':singleton' => 'certain',
        ]);
    }

    #[Test]
    public function it_continues_into_the_bindings_of_a_registered_provider(): void
    {
        // Registering a provider binds everything it binds, so the tree goes on
        // beneath its node, with the arrows leaving from it rather than the root.
        $this->assertEdges(RegistersSiblingServiceProvider::class, [
            RegistersSiblingServiceProvider::class.'->'.SiblingServiceProvider::class.':registers' => 'certain',
            SiblingServiceProvider::class.'->workbench.renderer:singleton' => 'certain',
            'workbench.renderer->'.HtmlReportRenderer::class.':singleton' => 'certain',
        ]);

        $nodes = $this->nodes(RegistersSiblingServiceProvider::class);

        $this->assertSame('provider', $nodes[SiblingServiceProvider::class]['kind']);
        $this->assertSame(1, $nodes[SiblingServiceProvider::class]['depth']);
        $this->assertSame(2, $nodes['workbench.renderer']['depth']);
        $this->assertSame(3, $nodes[HtmlReportRenderer::class]['depth']);
    }

    #[Test]
    public function it_follows_a_bound_class_into_its_constructor(): void
    {
        $edges = $this->resolvedEdges(ArchiveServiceProvider::class);

        $this->assertSame('inferred', $edges[ReportArchive::class.'->'.ArchiveStorage::class.':injects']);
        $this->assertSame('inferred', $edges[ArchiveStorage::class.'->'.ArchiveIndex::class.':injects']);

        // The contract the archive type-hints is the one this provider binds,
        // so the arrow meets the node the binding already drew.
        $this->assertSame('inferred', $edges[ReportArchive::class.'->'.ReportRenderer::class.':injects']);
        $this->assertSame('certain', $edges[ReportRenderer::class.'->'.HtmlReportRenderer::class.':bind']);

        $nodes = $this->resolvedNodes(ArchiveServiceProvider::class);

        $this->assertSame(1, $nodes[ReportArchive::class]['depth']);
        $this->assertSame(2, $nodes[ArchiveStorage::class]['depth']);
        $this->assertSame(3, $nodes[ArchiveIndex::class]['depth']);
    }

    #[Test]
    public function it_follows_a_class_the_provider_binds_even_when_no_constructor_was_asked_for(): void
    {
        // DatabaseSearchIndex is bound by BindingServiceProvider and needs the
        // transcoder — the hop the fixture exists for.
        $this->assertSame(
            'inferred',
            $this->resolvedEdges(BindingServiceProvider::class)[DatabaseSearchIndex::class.'->'.FfmpegTranscoder::class.':injects'],
        );
    }

    #[Test]
    public function it_draws_a_cycle_once_and_stops(): void
    {
        $edges = $this->resolvedEdges(ArchiveServiceProvider::class);

        $this->assertArrayHasKey(ArchiveStorage::class.'->'.ArchiveIndex::class.':injects', $edges);
        $this->assertArrayHasKey(ArchiveIndex::class.'->'.ArchiveStorage::class.':injects', $edges);

        $this->assertFalse($this->resolve(ArchiveServiceProvider::class)['truncated']);
    }

    #[Test]
    public function it_stops_at_framework_code(): void
    {
        $nodes = $this->resolvedNodes(ArchiveServiceProvider::class);

        $this->assertSame('framework', $nodes[Filesystem::class]['origin']);

        // Filesystem's own constructor is not read, so nothing hangs off it.
        $this->assertSame([], array_values(array_filter(
            $this->resolve(ArchiveServiceProvider::class)['edges'],
            fn (array $edge) => $edge['source'] === Filesystem::class,
        )));
    }

    #[Test]
    public function it_reports_a_parameter_it_cannot_name_as_an_unknown_leaf(): void
    {
        $description = $this->resolve(ArchiveServiceProvider::class);

        $unresolved = array_column(array_filter(
            $description['nodes'],
            fn (array $node) => $node['kind'] === 'unresolved',
        ), 'label');

        sort($unresolved);

        // The union names two classes and so names neither; the untyped one is
        // still something the container has to fill. The scalar with a
        // default is configuration and does not appear.
        $this->assertSame([
            '$metadata',
            '$preview: '.FfmpegTranscoder::class.'|'.NullSearchIndex::class,
        ], $unresolved);

        $this->assertTrue($description['partial']);
    }

    #[Test]
    public function it_cuts_the_walk_at_the_depth_cap_and_says_so(): void
    {
        $description = $this->resolve(ArchiveServiceProvider::class, maxDepth: 2);
        $nodes = array_column($description['nodes'], null, 'id');

        $this->assertArrayHasKey(ArchiveStorage::class, $nodes);
        $this->assertArrayNotHasKey(ArchiveIndex::class, $nodes);
        $this->assertArrayNotHasKey(Filesystem::class, $nodes);
        $this->assertTrue($description['truncated']);
    }

    #[Test]
    public function it_cuts_the_walk_at_the_node_cap_and_says_so(): void
    {
        $description = $this->resolve(ArchiveServiceProvider::class, maxNodes: 4);

        $this->assertCount(4, $description['nodes']);
        $this->assertTrue($description['truncated']);
    }

    #[Test]
    public function it_reads_all_three_spellings_of_resolving(): void
    {
        // make() and app() name the same contract, so they are one arrow.
        $this->assertEdges(ResolvingServiceProvider::class, [
            ResolvingServiceProvider::class.'->'.SearchIndex::class.':resolves' => 'certain',
            ResolvingServiceProvider::class.'->'.FfmpegTranscoder::class.':resolves' => 'certain',
        ]);
    }

    #[Test]
    public function it_counts_side_effects_as_badges_not_edges(): void
    {
        $description = $this->describe(SideEffectServiceProvider::class);

        $this->assertSame([], $description['edges']);
        $this->assertEqualsCanonicalizing([
            'config' => 1,
            'events' => 1,
            'gates' => 1,
            'publishes' => 1,
            'migrations' => 1,
        ], $description['side_effects']);
    }

    #[Test]
    public function it_reports_what_it_could_not_read_instead_of_dropping_it(): void
    {
        $description = $this->describe(OpaqueServiceProvider::class);

        $this->assertTrue($description['partial']);

        $unresolved = array_values(array_filter(
            $description['nodes'],
            fn (array $node) => $node['kind'] === 'unresolved',
        ));

        // The loop's bind() with variable arguments, and the helper method.
        $this->assertSame(
            ['$this->app->bind($abstract, $concrete)', '$this->bindTranscoder()'],
            array_column($unresolved, 'label'),
        );

        $this->assertEqualsCanonicalizing(
            ['bind', 'calls'],
            array_column($description['edges'], 'kind'),
        );

        $this->assertSame(['unknown', 'unknown'], array_column($description['edges'], 'confidence'));
    }

    #[Test]
    public function it_is_not_partial_when_everything_was_literal(): void
    {
        $this->assertFalse($this->describe(BindingServiceProvider::class)['partial']);
    }

    /** @return array<class-string, string> */
    protected function providers(): array
    {
        return (new ProviderReader)->read();
    }

    /** @return array<string, mixed> */
    protected function describe(string $provider): array
    {
        return app(ProviderInspector::class)->describe($provider)->toArray();
    }

    /**
     * Inspected, then walked into constructors.
     *
     * @return array<string, mixed>
     */
    protected function resolve(string $provider, ?int $maxDepth = null, ?int $maxNodes = null): array
    {
        $tree = app(ProviderInspector::class)->tree($provider);

        (new DependencyResolver($maxDepth, $maxNodes))->resolve($tree);

        return $tree->build()->toArray();
    }

    /** @return array<string, string> edge id => confidence */
    protected function resolvedEdges(string $provider): array
    {
        return array_column($this->resolve($provider)['edges'], 'confidence', 'id');
    }

    /** @return array<string, array<string, mixed>> */
    protected function resolvedNodes(string $provider): array
    {
        return array_column($this->resolve($provider)['nodes'], null, 'id');
    }

    /** @return array<string, array<string, mixed>> */
    protected function nodes(string $provider): array
    {
        return array_column($this->describe($provider)['nodes'], null, 'id');
    }

    /**
     * Exactly these edges, by id, each at the confidence given.
     *
     * @param  array<string, string>  $expected
     */
    protected function assertEdges(string $provider, array $expected): void
    {
        $edges = array_column($this->describe($provider)['edges'], 'confidence', 'id');

        ksort($edges);
        ksort($expected);

        $this->assertSame($expected, $edges);
    }
}
