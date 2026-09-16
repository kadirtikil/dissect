<?php

namespace Workbench\App\Providers;

/**
 * A plain class that happens to live in the providers directory.
 *
 * Nothing stops an application keeping a helper next to its providers, and a
 * scan that lists every `.php` file it finds would report this one as a
 * provider. It is a fixture for the filter, not for the tree.
 */
class ProviderSupport
{
    public static function defaultTags(): array
    {
        return ['workbench'];
    }
}
