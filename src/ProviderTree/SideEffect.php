<?php

namespace KdrDev\Dissect\ProviderTree;

/**
 * Something a provider does that is not a dependency.
 *
 * A provider that listens for an event, defines a gate, merges config and
 * publishes a stub has told you a great deal about itself and named almost
 * nothing it depends on. Drawn as edges these would outnumber the real
 * dependencies and bury them; dropped entirely, a provider that is *only* side
 * effects would render as an empty tree and look broken.
 *
 * So they ride on the provider node as badges: present, countable, and out of
 * the way.
 */
enum SideEffect: string
{
    /** `Event::listen`, or a listener registered any other way. */
    case Events = 'events';

    /** `Gate::define`, a policy registered, an ability named. */
    case Gates = 'gates';

    /** `mergeConfigFrom`. */
    case Config = 'config';

    /** `publishes` — files the application can copy out of the package. */
    case Publishes = 'publishes';

    /** `loadMigrationsFrom`. */
    case Migrations = 'migrations';

    /** Routes registered from the provider. */
    case Routes = 'routes';

    /** `loadViewsFrom`, view composers, Blade directives. */
    case Views = 'views';

    /** `commands()` — console commands the provider registers. */
    case Commands = 'commands';
}
