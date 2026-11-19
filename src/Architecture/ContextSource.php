<?php

namespace KdrDev\Dissect\Architecture;

/**
 * How dissect learned that a domain exists.
 *
 * Travels with every domain, the way `confidence` travels with an inferred
 * route shape: a domain read from a folder layout is a guess about intent, one
 * written into `.dissect/domains.json` is a statement of it, and the page
 * should be able to tell the two apart.
 */
enum ContextSource: string
{
    /** Named in `.dissect/domains.json`. */
    case Config = 'config';

    /** Named by a `#[Domain('…')]` attribute on a class. */
    case Attribute = 'attribute';

    /** `Modules/{Name}`, as nwidart/laravel-modules lays them out. */
    case Modules = 'modules';

    /** `app-modules/{name}`, as InterNACHI/modular lays them out. */
    case AppModules = 'app-modules';

    /** `app/Domain/{Name}` or `src/Domain/{Name}`, Spatie's "Beyond CRUD" layout. */
    case DomainFolder = 'domain-folder';

    /** `src/{Name}/{Domain,Application,Infrastructure}`: one folder per context, layered inside. */
    case Hexagonal = 'hexagonal';

    /** `app/Models/{Name}` with a sibling such as `app/Http/Controllers/{Name}`. */
    case LayerFolders = 'layer-folders';

    /** `packages/{name}`, a local Composer package per domain. */
    case Packages = 'packages';
}
