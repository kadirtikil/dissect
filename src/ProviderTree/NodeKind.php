<?php

namespace KdrDev\Dissect\ProviderTree;

/**
 * What a node in a provider's tree stands for.
 *
 * The distinction that matters on the surface is between the thing somebody
 * asks for and the thing they get: a contract is a name in the container, a
 * concrete is a class that gets constructed, and drawing both as "a class"
 * loses the only interesting fact about a binding.
 */
enum NodeKind: string
{
    /** The provider itself — the root of its own tree, or a node in another's. */
    case Provider = 'provider';

    /**
     * The abstract half of a binding: an interface, an abstract class, or a
     * plain string key like `workbench.transcoder`, which the container is
     * equally happy to bind and which resolves to nothing by reflection.
     */
    case Contract = 'contract';

    /** A class that is actually constructed, and whose constructor can be read. */
    case Concrete = 'concrete';

    /**
     * A dependency that is known to exist and could not be named.
     *
     * A binding built in a loop, a union-typed constructor parameter, a class
     * string assembled at runtime. Reported rather than dropped: a tree missing
     * a branch silently is worse than one that says it stopped here.
     */
    case Unresolved = 'unresolved';
}
