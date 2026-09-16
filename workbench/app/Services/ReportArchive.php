<?php

namespace Workbench\App\Services;

use Workbench\App\Contracts\ReportRenderer;

/**
 * The top of a constructor chain, with every parameter shape worth reading.
 *
 * A class to follow, a contract the provider binds, a union type that names
 * two classes and so names neither, an untyped parameter the container would
 * still have to fill, and a scalar with a default that is configuration rather
 * than a dependency.
 */
class ReportArchive
{
    public function __construct(
        protected ArchiveStorage $storage,
        protected ReportRenderer $renderer,
        protected FfmpegTranscoder|NullSearchIndex $preview,
        protected $metadata,
        protected string $disk = 'local',
    ) {}
}
