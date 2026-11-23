<?php

namespace KdrDev\Dissect\Tests\Jobs;

use Illuminate\Support\Facades\File;
use KdrDev\Dissect\Jobs\DispatchScanner;
use KdrDev\Dissect\Jobs\JobExporter;
use KdrDev\Dissect\Routes\Ast\ClassSource;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Dispatch sites written into a scratch directory, for the spellings the
 * workbench does not use and the queue each one lands on.
 */
class DispatchScannerTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/dissect-dispatch-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->directory.'/Http');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function on_queue_chained_onto_the_dispatch_helper_is_read(): void
    {
        $this->write('Http/SendController.php', <<<'PHP'
            <?php
            namespace App\Http;
            use App\Jobs\SendInvoice;
            use App\Jobs\Reindex;
            use Illuminate\Support\Facades\Bus;
            class SendController
            {
                public function store()
                {
                    dispatch(new SendInvoice)->onQueue('mail');
                    Bus::batch([new Reindex])->onQueue('search')->dispatch();
                }
            }
            PHP);

        $sites = $this->scanner([$this->directory])->sites(['App\Jobs\SendInvoice', 'App\Jobs\Reindex']);

        $this->assertSame('mail', $sites['App\Jobs\SendInvoice'][0]['queue']);
        $this->assertSame('dispatch', $sites['App\Jobs\SendInvoice'][0]['method']);
        $this->assertSame('search', $sites['App\Jobs\Reindex'][0]['queue']);
    }

    #[Test]
    public function a_new_passed_to_something_else_is_not_queued_by_what_follows(): void
    {
        $this->write('Http/MailController.php', <<<'PHP'
            <?php
            namespace App\Http;
            use App\Jobs\SendInvoice;
            class MailController
            {
                public function store($pipeline)
                {
                    $pipeline->send(new SendInvoice)->onQueue('mail');
                }
            }
            PHP);

        $sites = $this->scanner([$this->directory])->sites(['App\Jobs\SendInvoice']);

        $this->assertNull($sites['App\Jobs\SendInvoice'][0]['queue']);
    }

    #[Test]
    public function overlapping_paths_find_each_site_once(): void
    {
        $this->write('Http/SendController.php', <<<'PHP'
            <?php
            namespace App\Http;
            use App\Jobs\SendInvoice;
            class SendController
            {
                public function store() { SendInvoice::dispatch(); }
            }
            PHP);

        $sites = $this->scanner([$this->directory, $this->directory.'/Http'])->sites(['App\Jobs\SendInvoice']);

        $this->assertCount(1, $sites['App\Jobs\SendInvoice']);
    }

    #[Test]
    public function a_queue_named_at_one_site_and_not_another_is_mixed(): void
    {
        $exporter = $this->app->make(JobExporter::class);
        $queue = fn (array $sites) => (fn () => $this->queue(['queue' => null, 'queue_source' => 'default'], $sites))->call($exporter);

        // A site that names no queue sends the job to the default one.
        $this->assertSame(['queue' => null, 'queue_source' => 'mixed'], $queue([['queue' => 'mail'], ['queue' => null]]));
        $this->assertSame(['queue' => 'mail', 'queue_source' => 'dispatch'], $queue([['queue' => 'mail'], ['queue' => 'mail']]));
        $this->assertSame(['queue' => null, 'queue_source' => 'default'], $queue([['queue' => null]]));
        $this->assertSame(['queue' => null, 'queue_source' => 'default'], $queue([]));
    }

    /** @param  array<int, string>  $paths */
    protected function scanner(array $paths): DispatchScanner
    {
        return new DispatchScanner(new ClassSource, $paths);
    }

    protected function write(string $path, string $contents): void
    {
        File::put($this->directory.'/'.$path, $contents);
    }
}
