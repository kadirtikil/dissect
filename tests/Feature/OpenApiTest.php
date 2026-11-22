<?php

namespace KdrDev\Dissect\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * /openapi.json: the API's OpenAPI document, for the Routes page to offer.
 */
class OpenApiTest extends TestCase
{
    #[Test]
    public function it_serves_the_document_the_command_writes(): void
    {
        $response = $this->get('/dissect/openapi.json')->assertOk();

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $document = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/api/posts', $document['paths']);

        // Byte for byte what the terminal writes, so a committed file and
        // a downloaded one never differ.
        Artisan::call('dissect:export', ['surface' => 'routes', '--format' => 'openapi']);
        $this->assertSame(Artisan::output(), $response->getContent());
    }
}
