<?php

namespace KdrDev\Dissect\Tests\Feature;

use KdrDev\Dissect\Jobs\JobExporter;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Jobs\PruneComments;
use Workbench\App\Jobs\PublishPost;
use Workbench\App\Jobs\RebuildSearchIndex;
use Workbench\App\Jobs\SyncAuthorProfile;
use Workbench\App\Listeners\NotifyFollowers;
use Workbench\App\Mail\WeeklyDigest;

/**
 * The job list.
 *
 * Asserted against the fixture queueables in `workbench/app/`, which exist for
 * the cases that are awkward rather than the ones that are typical: a queue
 * named in a constructor because PHP forbids the property, middleware built
 * from the job's own state, a mailable queued through an API the scanner does
 * not enumerate, a job dispatched onto two different queues, and one nothing
 * dispatches at all.
 */
class JobsTest extends TestCase
{
    #[Test]
    public function it_finds_queueables_by_interface_not_by_folder(): void
    {
        $jobs = $this->jobs();

        $this->assertArrayHasKey(PublishPost::class, $jobs);
        $this->assertArrayHasKey(NotifyFollowers::class, $jobs);
        $this->assertArrayHasKey(WeeklyDigest::class, $jobs);
    }

    #[Test]
    public function it_skips_an_abstract_base_job(): void
    {
        // Real code, but not a thing that can sit on a queue.
        $this->assertArrayNotHasKey('Workbench\App\Jobs\BaseImport', $this->jobs());
    }

    #[Test]
    public function it_reads_the_retry_settings_off_the_class(): void
    {
        $retry = $this->jobs()[PublishPost::class]['retry'];

        $this->assertSame(3, $retry['tries']);
        $this->assertSame(120, $retry['timeout']);
        $this->assertFalse($retry['retryUntil']);
    }

    #[Test]
    public function it_reads_a_backoff_written_as_a_method(): void
    {
        // Never called: backoff() on an unconstructed job is free to reach for
        // state no constructor has set.
        $this->assertSame([10, 60, 300], $this->jobs()[PublishPost::class]['retry']['backoff']);
    }

    #[Test]
    public function it_reads_a_backoff_written_as_a_scalar_property(): void
    {
        // One int and a list mean the same thing to the worker.
        $this->assertSame([30], $this->jobs()[NotifyFollowers::class]['retry']['backoff']);
    }

    #[Test]
    public function it_reports_a_deadline_without_pretending_to_know_it(): void
    {
        // retryUntil() returns a time computed when the job is queued, so there
        // is no value to report — only that tries is not the whole story.
        $this->assertTrue($this->jobs()[RebuildSearchIndex::class]['retry']['retryUntil']);
    }

    #[Test]
    public function it_reads_middleware_without_constructing_the_job(): void
    {
        // `new WithoutOverlapping($this->post->id)` — running this would fatal.
        $this->assertSame(['WithoutOverlapping'], $this->jobs()[PublishPost::class]['middleware']);
    }

    #[Test]
    public function it_names_the_queue_a_job_sets_in_its_constructor(): void
    {
        // The only spelling available to a job using Queueable: PHP rejects a
        // `public $queue` that redefines the trait's own property.
        $job = $this->jobs()[PublishPost::class];

        $this->assertSame('publishing', $job['queue']);
        $this->assertSame('constructor', $job['queue_source']);
    }

    #[Test]
    public function it_names_the_queue_a_listener_declares_as_a_property(): void
    {
        // A listener inherits nothing that claims $queue, so it can.
        $listener = $this->jobs()[NotifyFollowers::class];

        $this->assertSame('listeners', $listener['queue']);
        $this->assertSame('property', $listener['queue_source']);
    }

    #[Test]
    public function it_takes_the_queue_from_the_dispatch_site_when_the_class_names_none(): void
    {
        $job = $this->jobs()[PruneComments::class];

        $this->assertSame('maintenance', $job['queue']);
        $this->assertSame('dispatch', $job['queue_source']);
    }

    #[Test]
    public function it_refuses_to_pick_between_two_queues(): void
    {
        // Dispatched onto 'sync' from one action and 'profiles' from another.
        // Choosing either would be inventing a fact.
        $job = $this->jobs()[SyncAuthorProfile::class];

        $this->assertNull($job['queue']);
        $this->assertSame('mixed', $job['queue_source']);
    }

    #[Test]
    public function it_reads_the_connection_set_in_a_constructor(): void
    {
        $this->assertSame('redis', $this->jobs()[RebuildSearchIndex::class]['connection']);
    }

    #[Test]
    public function it_tells_the_four_queueable_shapes_apart(): void
    {
        $jobs = $this->jobs();

        $this->assertSame('job', $jobs[PublishPost::class]['kind']);
        $this->assertSame('listener', $jobs[NotifyFollowers::class]['kind']);
        $this->assertSame('mailable', $jobs[WeeklyDigest::class]['kind']);
    }

    #[Test]
    public function it_reports_which_events_a_listener_is_registered_against(): void
    {
        // Nothing about the class says it is a listener; the dispatcher does.
        $this->assertSame(
            ['Workbench\App\Events\PostPublished'],
            $this->jobs()[NotifyFollowers::class]['events'],
        );
    }

    #[Test]
    public function it_resolves_a_constructor_type_hint_to_a_graph_node_id(): void
    {
        // The join to the graph, and the same ids schema.json uses as keys.
        $job = $this->jobs()[PublishPost::class];

        $this->assertSame(['Post'], $job['models']);
        $this->assertSame('Post', $job['payload'][0]['model']);
        $this->assertSame('notifyFollowers', $job['payload'][1]['name']);
        $this->assertTrue($job['payload'][1]['optional']);
        $this->assertNull($job['payload'][1]['model']);
    }

    #[Test]
    public function it_links_a_dispatch_site_to_the_endpoint_it_sits_in(): void
    {
        $site = $this->jobs()[PublishPost::class]['dispatched_by'][0];

        $this->assertSame('PostController@store', $site['label']);
        $this->assertSame('dispatch', $site['method']);
        $this->assertSame('POST:api/posts', $site['route']);
        $this->assertTrue($site['afterCommit']);
    }

    #[Test]
    public function it_links_a_dispatch_site_inside_a_route_closure(): void
    {
        // A closure has no controller class to match on, so both sides address
        // it by where it is written.
        $site = $this->jobs()[PruneComments::class]['dispatched_by'][0];

        $this->assertSame('GET:api/authors/{author}/stats', $site['route']);
        $this->assertStringStartsWith('api.php:', $site['label']);
    }

    #[Test]
    public function it_finds_a_queueable_handed_to_an_api_it_does_not_enumerate(): void
    {
        // Mail::to($author)->queue(new WeeklyDigest($author)) — kept because
        // WeeklyDigest is a class discovery already found, not because the
        // scanner knows what Mail::to() is.
        $site = $this->jobs()[WeeklyDigest::class]['dispatched_by'][0];

        $this->assertSame('queue', $site['method']);
        $this->assertSame('AuthorController@store', $site['label']);
        $this->assertSame('POST:authors', $site['route']);
    }

    #[Test]
    public function it_reports_a_job_nothing_dispatches_as_exactly_that(): void
    {
        // Dead code or dispatched dynamically. The surface cannot tell which,
        // and an empty list is the honest answer to both.
        $this->assertSame([], $this->jobs()[RebuildSearchIndex::class]['dispatched_by']);
    }

    #[Test]
    public function it_serves_the_job_list_with_the_signal_that_describes_it(): void
    {
        $response = $this->get('dissect/jobs.json');

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');

        $payload = $response->json();

        $this->assertArrayHasKey('jobs', $payload);
        $this->assertArrayHasKey('fingerprint', $payload);
    }

    #[Test]
    public function it_reports_the_job_signal_only_when_asked_for_it(): void
    {
        // The walk is the widest this package does; a session on the graph
        // should not pay for it.
        $this->get('dissect/fingerprint')->assertJsonMissingPath('jobs');
        $this->get('dissect/fingerprint?jobs=1')->assertJsonStructure(['jobs']);
    }

    /**
     * The export keyed by class, since that is how the client addresses a job.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function jobs(): array
    {
        $export = $this->app->make(JobExporter::class)->export();

        return array_column($export['jobs'], null, 'id');
    }
}
