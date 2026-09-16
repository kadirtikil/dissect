<?php

namespace KdrDev\Dissect\Tests;

use KdrDev\Dissect\ProviderTree\EdgeKind;
use KdrDev\Dissect\ProviderTree\NodeKind;
use KdrDev\Dissect\ProviderTree\Origin;
use KdrDev\Dissect\ProviderTree\ProviderDescription;
use KdrDev\Dissect\ProviderTree\ProviderEdge;
use KdrDev\Dissect\ProviderTree\ProviderNode;
use KdrDev\Dissect\ProviderTree\SideEffect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * The wire shape of one provider's tree.
 *
 * Built by hand rather than read from a fixture, so a failure here is the
 * payload changing and never the inspector. Nothing in it touches the
 * framework, so it runs without booting Laravel.
 */
class ProviderDescriptionTest extends PhpUnitTestCase
{
    #[Test]
    public function it_round_trips_a_description_to_the_wire_shape(): void
    {
        $this->assertSame([
            'provider' => 'App\Providers\ReportServiceProvider',
            'nodes' => [
                [
                    'id' => 'App\Providers\ReportServiceProvider',
                    'kind' => 'provider',
                    'label' => 'ReportServiceProvider',
                    'origin' => 'app',
                    'depth' => 0,
                    'confidence' => 'certain',
                    'class' => 'App\Providers\ReportServiceProvider',
                    'file' => 'app/Providers/ReportServiceProvider.php',
                    'summary' => 'Wires up report rendering.',
                ],
                [
                    'id' => 'App\Contracts\ReportRenderer',
                    'kind' => 'contract',
                    'label' => 'ReportRenderer',
                    'origin' => 'app',
                    'depth' => 1,
                    'confidence' => 'certain',
                    'class' => 'App\Contracts\ReportRenderer',
                    'file' => null,
                    'summary' => null,
                ],
                [
                    'id' => 'App\Services\PdfReportRenderer',
                    'kind' => 'concrete',
                    'label' => 'PdfReportRenderer',
                    'origin' => 'app',
                    'depth' => 2,
                    'confidence' => 'certain',
                    'class' => 'App\Services\PdfReportRenderer',
                    'file' => null,
                    'summary' => null,
                ],
            ],
            'edges' => [
                [
                    'id' => 'App\Providers\ReportServiceProvider->App\Contracts\ReportRenderer:contextual@App\Services\ReportMailer',
                    'source' => 'App\Providers\ReportServiceProvider',
                    'target' => 'App\Contracts\ReportRenderer',
                    'kind' => 'contextual',
                    'confidence' => 'certain',
                    'consumer' => 'App\Services\ReportMailer',
                ],
                [
                    'id' => 'App\Contracts\ReportRenderer->App\Services\PdfReportRenderer:bind',
                    'source' => 'App\Contracts\ReportRenderer',
                    'target' => 'App\Services\PdfReportRenderer',
                    'kind' => 'bind',
                    'confidence' => 'certain',
                    'consumer' => null,
                ],
            ],
            'side_effects' => ['events' => 2],
            'deferred' => true,
            'provides' => ['App\Contracts\ReportRenderer'],
            'partial' => false,
        ], $this->description()->toArray());
    }

    #[Test]
    public function it_is_partial_when_any_node_is_unresolved(): void
    {
        $description = $this->description(extra: new ProviderNode(
            id: 'unresolved:0',
            kind: NodeKind::Unresolved,
            label: '$class',
            origin: Origin::None,
            depth: 1,
            confidence: 'unknown',
        ));

        $this->assertTrue($description->partial());
        $this->assertTrue($description->toArray()['partial']);
    }

    protected function description(?ProviderNode $extra = null): ProviderDescription
    {
        $provider = 'App\Providers\ReportServiceProvider';

        $nodes = [
            new ProviderNode(
                id: $provider,
                kind: NodeKind::Provider,
                label: 'ReportServiceProvider',
                origin: Origin::App,
                depth: 0,
                confidence: 'certain',
                class: $provider,
                file: 'app/Providers/ReportServiceProvider.php',
                summary: 'Wires up report rendering.',
            ),
            new ProviderNode(
                id: 'App\Contracts\ReportRenderer',
                kind: NodeKind::Contract,
                label: 'ReportRenderer',
                origin: Origin::App,
                depth: 1,
                confidence: 'certain',
                class: 'App\Contracts\ReportRenderer',
            ),
            new ProviderNode(
                id: 'App\Services\PdfReportRenderer',
                kind: NodeKind::Concrete,
                label: 'PdfReportRenderer',
                origin: Origin::App,
                depth: 2,
                confidence: 'certain',
                class: 'App\Services\PdfReportRenderer',
            ),
        ];

        if ($extra !== null) {
            $nodes[] = $extra;
        }

        return new ProviderDescription(
            provider: $provider,
            nodes: $nodes,
            edges: [
                new ProviderEdge(
                    source: $provider,
                    target: 'App\Contracts\ReportRenderer',
                    kind: EdgeKind::Contextual,
                    confidence: 'certain',
                    consumer: 'App\Services\ReportMailer',
                ),
                new ProviderEdge(
                    source: 'App\Contracts\ReportRenderer',
                    target: 'App\Services\PdfReportRenderer',
                    kind: EdgeKind::Bind,
                    confidence: 'certain',
                ),
            ],
            sideEffects: [SideEffect::Events->value => 2],
            deferred: true,
            provides: ['App\Contracts\ReportRenderer'],
        );
    }
}
