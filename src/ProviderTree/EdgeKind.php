<?php

namespace KdrDev\Dissect\ProviderTree;

/**
 * How one node came to depend on another.
 *
 * Every case is a different sentence about the same arrow, and the difference is
 * worth keeping: `bind` is a decision the provider made, `injects` is one the
 * constructor made, and `resolves` is the provider needing something it did not
 * decide at all.
 */
enum EdgeKind: string
{
    /** `$this->app->bind(Contract::class, Concrete::class)`. */
    case Bind = 'bind';

    /** `singleton()` — bound once and shared. */
    case Singleton = 'singleton';

    /** `scoped()` — a singleton the framework forgets between requests. */
    case Scoped = 'scoped';

    /** `instance()` — an object, already constructed at registration time. */
    case Instance = 'instance';

    /** `when(Consumer)->needs(Contract)->give(Concrete)`, the only edge with a third class on it. */
    case Contextual = 'contextual';

    /** `$this->app->register(OtherProvider::class)` — an edge to a provider, not to a service. */
    case Registers = 'registers';

    /** `make()`, `resolve()` or `app()`: needed, but not decided here. */
    case Resolves = 'resolves';

    /** A constructor parameter — the hop that turns a list of bindings into a tree. */
    case Injects = 'injects';
}
