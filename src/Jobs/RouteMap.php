<?php

namespace KdrDev\Dissect\Jobs;

use Illuminate\Routing\Route;
use KdrDev\Dissect\Routes\ActionResolver;
use KdrDev\Dissect\Routes\RouteCollector;
use KdrDev\Dissect\Routes\RouteExporter;
use ReflectionFunctionAbstract;
use Throwable;

/**
 * Turns "the code a dispatch site sits in" into a route id.
 *
 * This is the jobs surface's answer to {@see \KdrDev\Dissect\Routes\ModelLinker}
 * — the piece that makes it part of dissect rather than a second listing. A job
 * dispatched from `InvoiceController@store` is a job an endpoint causes, and
 * the endpoint is already on screen one page away.
 *
 * Both halves of the join are addressed the same way whatever the shape of the
 * action: a controller by its fully qualified `Class@method`, a closure by
 * where it is written. A name that resolves to nothing stays nothing — a link
 * to an endpoint that is not in the table is worse than no link.
 */
class RouteMap
{
    /** @var array<string, string>|null context key => route id */
    protected ?array $map = null;

    public function __construct(
        protected RouteCollector $collector,
        protected ActionResolver $actions,
    ) {}

    /** The endpoint whose action contains this context, or null. */
    public function forContext(?string $context): ?string
    {
        if ($context === null || $context === '') {
            return null;
        }

        $this->load();

        return $this->map[$context] ?? null;
    }

    protected function load(): void
    {
        if ($this->map !== null) {
            return;
        }

        $this->map = [];

        foreach ($this->collector->all() as $route) {
            try {
                $described = $this->collector->describe($route);
                $action = $this->actions->describe($route);
                $id = RouteExporter::id($described['methods'], $described['uri']);
            } catch (Throwable) {
                // One unreadable route costs its own link, not the map.
                continue;
            }

            foreach ($this->keysFor($route, $action) as $key) {
                // First registration wins. One method can serve several URIs,
                // and a dispatch inside it belongs to all of them equally — so
                // any choice is arbitrary, and a stable one beats a shifting
                // one.
                $this->map[$key] ??= $id;
            }
        }
    }

    /**
     * How this route's action is addressed, from both directions it can be
     * written in.
     *
     * @param  array{type: string, class: string|null, method: string|null}  $action
     * @return array<int, string>
     */
    protected function keysFor(Route $route, array $action): array
    {
        if ($action['class'] !== null && $action['method'] !== null) {
            return [ltrim($action['class'], '\\').'@'.$action['method']];
        }

        $key = $this->closureKey($this->actions->reflect($route));

        return $key === null ? [] : [$key];
    }

    /**
     * `routes/api.php:22` — the spelling {@see DispatchScanner} produces for
     * code inside the same closure.
     */
    protected function closureKey(?ReflectionFunctionAbstract $reflection): ?string
    {
        if ($reflection === null) {
            return null;
        }

        $file = $reflection->getFileName();

        return $file === false || $file === null
            ? null
            : ProjectPath::relative($file).':'.$reflection->getStartLine();
    }
}
