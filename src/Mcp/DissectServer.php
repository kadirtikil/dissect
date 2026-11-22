<?php

namespace KdrDev\Dissect\Mcp;

use Composer\InstalledVersions;
use KdrDev\Dissect\Mcp\Tools\ClassReferences;
use KdrDev\Dissect\Mcp\Tools\Domain;
use KdrDev\Dissect\Mcp\Tools\Job;
use KdrDev\Dissect\Mcp\Tools\Model;
use KdrDev\Dissect\Mcp\Tools\Provider;
use KdrDev\Dissect\Mcp\Tools\Route;
use KdrDev\Dissect\Mcp\Tools\Search;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * `php artisan mcp:start dissect`: the app's structure, for an agent.
 *
 * The same reading the page does, answered one entry at a time in the
 * Markdown the export command prints, so an agent asks about the one model
 * or route it is working on rather than reading the whole app into context.
 * Every tool is read-only: nothing is written, booted or dispatched.
 */
#[Name('dissect')]
#[Instructions(<<<'MD'
    dissect reads this Laravel app's structure from its source: models and relations, HTTP routes with request and response shapes, queued jobs and where they are dispatched, service provider bindings, domains, and which class uses which. Every answer is read fresh, so it reflects the code as it is now.

    Start with dissect_search when you have a name but not its kind. Call dissect_model, dissect_route, dissect_job or dissect_provider without arguments for an index, or with a name for the full entry. Before changing a class, dissect_class says what depends on it. Short class names work; when two classes share one, the namespace segments that tell them apart are shown.

    Lines marked inferred or unknown are dissect's best reading, not a fact; check the source before relying on them. Nothing here runs the app's code.
    MD)]
class DissectServer extends Server
{
    protected array $tools = [
        Search::class,
        Model::class,
        Route::class,
        Job::class,
        Provider::class,
        Domain::class,
        ClassReferences::class,
    ];

    /** The version installed, so a client's logs say which dissect answered. */
    public function createContext(): ServerContext
    {
        $this->version = InstalledVersions::isInstalled('kdr-dev/dissect')
            ? (InstalledVersions::getPrettyVersion('kdr-dev/dissect') ?? 'dev')
            : 'dev';

        return parent::createContext();
    }
}
