<?php

namespace KdrDev\Dissect\Routes;

use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Throwable;

/**
 * Works out what a route actually runs, and whose code it is.
 *
 * The reflection handle it hands back is what the request and response
 * analyzers read parameter and return types from, so both the controller and
 * the closure cases arrive at them as the same thing — a
 * {@see ReflectionFunctionAbstract}.
 */
class ActionResolver
{
    /** Framework controllers standing in for a route with no class of its own. */
    protected const SYNTHETIC = [
        'Illuminate\Routing\ViewController' => 'view',
        'Illuminate\Routing\RedirectController' => 'redirect',
    ];

    /**
     * @return array{type: string, class: string|null, method: string|null, label: string}
     */
    public function describe(Route $route): array
    {
        $name = $route->getActionName();

        if ($name === 'Closure') {
            return [
                'type' => 'closure',
                'class' => null,
                'method' => null,
                // Where it was written is the only identity a closure has, and
                // it is the only thing that helps somebody find it again.
                'label' => $this->closureOrigin($route) ?? 'Closure',
            ];
        }

        $class = $route->getControllerClass() ?? $this->classFrom($name);

        // An invokable controller is registered as the bare class name, so
        // there is no `@method` to split off.
        $method = str_contains($name, '@') ? substr($name, strpos($name, '@') + 1) : '__invoke';

        return [
            'type' => self::SYNTHETIC[ltrim((string) $class, '\\')] ?? 'controller',
            'class' => $class,
            'method' => $method,
            'label' => $class === null
                ? $name
                : class_basename($class).($method === '__invoke' ? '' : '@'.$method),
        ];
    }

    /**
     * The action as something with parameters and a return type.
     *
     * Null whenever the target cannot be reflected — an action pointing at a
     * class that no longer exists is a broken route, not a reason to fail the
     * whole export.
     */
    public function reflect(Route $route): ?ReflectionFunctionAbstract
    {
        try {
            $uses = $route->getAction('uses');

            if ($uses instanceof \Closure) {
                return new ReflectionFunction($uses);
            }

            $action = $this->describe($route);

            if ($action['class'] === null || ! class_exists($action['class'])) {
                return null;
            }

            $reflection = new ReflectionClass($action['class']);

            return $reflection->hasMethod((string) $action['method'])
                ? $reflection->getMethod((string) $action['method'])
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whose code this is: `app`, `vendor` or `framework`.
     *
     * Decided from the file the action is defined in rather than its namespace.
     * A namespace check would have to guess at the application's own prefix,
     * and gets the answer wrong for anything living outside `App\` — a Domain
     * folder, a path-repository package, or this package's own workbench.
     * Where a file sits relative to `vendor/` is not a guess.
     */
    public function group(Route $route): string
    {
        $file = $this->fileFor($route);

        if ($file === null) {
            // Nothing to inspect (an eval'd or generated action). The namespace
            // is the only signal left.
            return str_starts_with((string) $route->getActionName(), 'Illuminate\\')
                ? 'framework'
                : 'vendor';
        }

        $file = str_replace('\\', '/', $file);

        if (str_contains($file, '/vendor/laravel/framework/')) {
            return 'framework';
        }

        return str_contains($file, '/vendor/') ? 'vendor' : 'app';
    }

    protected function fileFor(Route $route): ?string
    {
        $reflection = $this->reflect($route);

        return $reflection === null ? null : ($reflection->getFileName() ?: null);
    }

    /** `routes/api.php:22` — the file and line the closure was written at. */
    protected function closureOrigin(Route $route): ?string
    {
        $reflection = $this->reflect($route);

        if ($reflection === null || ! $reflection->getFileName()) {
            return null;
        }

        return basename($reflection->getFileName()).':'.$reflection->getStartLine();
    }

    protected function classFrom(string $actionName): ?string
    {
        $class = str_contains($actionName, '@')
            ? substr($actionName, 0, strpos($actionName, '@'))
            : $actionName;

        return class_exists($class) ? $class : null;
    }
}
