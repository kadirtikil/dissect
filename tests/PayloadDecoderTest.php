<?php

namespace KdrDev\Dissect\Tests;

use KdrDev\Dissect\Queue\PayloadDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PayloadDecoderTest extends TestCase
{
    #[Test]
    public function it_reads_when_the_framework_queued_the_job(): void
    {
        // What core Laravel writes: createdAt, whole Unix seconds. pushedAt is
        // Horizon's and absent here.
        $decoded = (new PayloadDecoder)->decode((string) json_encode([
            'displayName' => 'App\Jobs\SendInvoice',
            'createdAt' => 1_732_012_345,
        ]));

        $this->assertSame(date(DATE_ATOM, 1_732_012_345), $decoded['pushed_at']);
    }

    #[Test]
    public function it_prefers_horizons_microsecond_timestamp_and_sends_it_as_a_date(): void
    {
        $decoded = (new PayloadDecoder)->decode((string) json_encode([
            'displayName' => 'App\Jobs\SendInvoice',
            'pushedAt' => '1732012399.8812',
            'createdAt' => 1_732_012_345,
        ]));

        // Not the raw microtime string, which no browser parses as a date.
        $this->assertSame(date(DATE_ATOM, 1_732_012_399), $decoded['pushed_at']);
    }

    #[Test]
    public function it_reports_no_time_for_a_payload_that_carries_none(): void
    {
        $this->assertNull((new PayloadDecoder)->decode('{"displayName":"App\\\\Jobs\\\\X"}')['pushed_at']);
    }
}
