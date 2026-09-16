<?php

namespace Workbench\App\Contracts;

/**
 * Bound as a singleton, and again through the `$singletons` property.
 *
 * Two providers claiming the same contract is not a mistake here: it is the
 * case where a tree has to say which provider a binding came from rather than
 * showing the contract once and losing its origin.
 */
interface Transcoder
{
    public function transcode(string $path): string;
}
