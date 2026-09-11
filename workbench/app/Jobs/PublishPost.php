<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Workbench\App\Models\Post;

/**
 * The job with something in every field.
 *
 * Its queue is named the only way a job using `Queueable` can name one — in the
 * constructor, because PHP refuses a `public $queue` that redefines the trait's
 * — and both of the declarations that have to be *read* rather than called are
 * here: `backoff()` returns a list, and `middleware()` builds middleware out of
 * the job's own state, which is precisely what running it would fail on.
 */
class PublishPost implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 120;

    public function __construct(public Post $post, public bool $notifyFollowers = true)
    {
        $this->onQueue('publishing');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new WithoutOverlapping($this->post->id)];
    }

    public function handle(): void
    {
        //
    }
}
