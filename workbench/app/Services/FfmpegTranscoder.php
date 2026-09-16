<?php

namespace Workbench\App\Services;

use Workbench\App\Contracts\Transcoder;

/**
 * A leaf: no constructor, so every branch that reaches it stops here.
 *
 * Reached two ways — bound as a singleton, and again as a constructor
 * dependency of DatabaseSearchIndex — which is what makes it the node a tree
 * must not draw twice under the same parent.
 */
class FfmpegTranscoder implements Transcoder
{
    public function transcode(string $path): string
    {
        return $path;
    }
}
