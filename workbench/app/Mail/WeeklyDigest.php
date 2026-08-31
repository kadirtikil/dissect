<?php

namespace Workbench\App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Workbench\App\Models\Author;

/**
 * A queued mailable — never dispatched by name, only constructed and handed to
 * `Mail::to(...)->queue(...)`.
 *
 * That is the case the scanner's rule exists for: enumerating every API that
 * can queue something would mean missing one, so a `new` of a class discovery
 * already found counts, whatever the call around it is called.
 */
class WeeklyDigest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Author $author)
    {
        $this->onQueue('mail');
    }

    public function build(): self
    {
        return $this->view('workbench::authors');
    }
}
