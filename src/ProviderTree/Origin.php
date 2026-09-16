<?php

namespace KdrDev\Dissect\ProviderTree;

/**
 * Whose code a node is.
 *
 * Recursion stops at the framework and at vendor code — walking into
 * `Illuminate\Container` costs a reflection per level for something nobody is
 * debugging — so the boundary is visible in the tree either way. Naming it
 * turns "the branch ends here" into "the branch ends here because this is not
 * your code", and it is what a framework-provider tier would key off if one is
 * ever added.
 */
enum Origin: string
{
    /** The application's own namespace. */
    case App = 'app';

    /** `Illuminate\*` — the framework itself. */
    case Framework = 'framework';

    /** Installed under vendor/, but not the framework. */
    case Vendor = 'vendor';

    /**
     * Not a class at all.
     *
     * A container key like `workbench.transcoder` belongs to nobody's
     * namespace, and calling it the application's would be a guess.
     */
    case None = 'none';
}
