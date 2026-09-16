<?php

namespace KdrDev\Dissect\ProviderTree;

use Illuminate\Contracts\Support\DeferrableProvider;
use KdrDev\Dissect\Routes\Ast\ClassSource;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\PrettyPrinter\Standard;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * What one provider binds, resolves and does, read from its source.
 *
 * Read, never run. Registering a provider to watch what it binds would mean
 * booting it — connecting to whatever its singletons connect to, merging its
 * config into the running application — and describing a provider must not be
 * able to do any of that.
 *
 * The price is that only what is written literally can be named. A binding
 * whose class-string is a variable, built in a loop, or tucked into a helper
 * method is still *seen* — the call is right there — so it is reported as an
 * unresolved node rather than dropped. A tree that stops and says so is
 * worth more than one that looks complete and is not.
 *
 * What comes back is the provider, the contracts it names, what they are bound
 * to, and the same again for every application provider it registers.
 * Following a concrete into its own constructor is the dependency resolver's
 * job.
 */
class ProviderInspector
{
    /** Container calls that decide what a name resolves to. */
    protected const BINDINGS = [
        'bind' => EdgeKind::Bind,
        'bindIf' => EdgeKind::Bind,
        'singleton' => EdgeKind::Singleton,
        'singletonIf' => EdgeKind::Singleton,
        'scoped' => EdgeKind::Scoped,
        'scopedIf' => EdgeKind::Scoped,
        'instance' => EdgeKind::Instance,
    ];

    /** Container calls that need a name without deciding it. */
    protected const RESOLUTIONS = ['make', 'makeWith', 'get'];

    /** `$this->…()` calls a provider inherits, and what each one amounts to. */
    protected const PROVIDER_EFFECTS = [
        'mergeConfigFrom' => SideEffect::Config,
        'replaceConfigRecursivelyFrom' => SideEffect::Config,
        'publishes' => SideEffect::Publishes,
        'publishesMigrations' => SideEffect::Publishes,
        'loadMigrationsFrom' => SideEffect::Migrations,
        'loadRoutesFrom' => SideEffect::Routes,
        'loadViewsFrom' => SideEffect::Views,
        'loadViewComponentsAs' => SideEffect::Views,
        'commands' => SideEffect::Commands,
    ];

    /** Facades whose every call is a side effect, by the name they are imported under. */
    protected const FACADE_EFFECTS = [
        'Illuminate\Support\Facades\Event' => SideEffect::Events,
        'Illuminate\Support\Facades\Gate' => SideEffect::Gates,
        'Illuminate\Support\Facades\Route' => SideEffect::Routes,
        'Illuminate\Support\Facades\View' => SideEffect::Views,
        'Illuminate\Support\Facades\Blade' => SideEffect::Views,
    ];

    protected const APP_FACADE = 'Illuminate\Support\Facades\App';

    protected Standard $printer;

    public function __construct(protected ClassSource $source)
    {
        $this->printer = new Standard;
    }

    /** One provider's direct tree, or null if it cannot be reflected at all. */
    public function describe(string $class): ?ProviderDescription
    {
        return $this->tree($class)?->build();
    }

    /**
     * The tree still open, for the dependency resolver to add to before it is
     * built.
     */
    public function tree(string $class): ?TreeBuilder
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return null;
        }

        $tree = new TreeBuilder($reflection->getName());

        $this->read($reflection, $tree);

        // Deferral is a fact about the root. A registered provider being
        // deferred changes when it loads, not what the root binds.
        $tree->deferred = $reflection->implementsInterface(DeferrableProvider::class);
        $tree->provides = $this->provides($reflection);

        return $tree;
    }

    /**
     * One provider's calls into the tree, from whichever node is current.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    protected function read(ReflectionClass $reflection, TreeBuilder $tree): void
    {
        $this->properties($reflection, $tree);

        foreach (['register', 'boot'] as $name) {
            $method = $this->source->method($reflection->getName(), $name);

            // Every call anywhere in the body, outermost first — a binding inside
            // a closure handed to `booted()` is still a binding this provider
            // makes.
            foreach ($method === null ? [] : $this->source->find($method, Expr::class) as $expr) {
                $this->call($expr, $reflection, $tree);
            }
        }
    }

    /**
     * `$bindings` and `$singletons`, which the framework registers on the
     * provider's behalf — so `register()` can be empty and the provider still
     * bind plenty.
     *
     * Read by reflection rather than from source: a property default is a
     * constant expression, so the value reflection hands back is exactly what
     * was written.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    protected function properties(ReflectionClass $reflection, TreeBuilder $tree): void
    {
        $defaults = $reflection->getDefaultProperties();

        foreach (['bindings' => EdgeKind::Bind, 'singletons' => EdgeKind::Singleton] as $property => $kind) {
            foreach ((array) ($defaults[$property] ?? []) as $abstract => $concrete) {
                // A list entry — `[Foo::class]` — binds the class to itself.
                if (is_int($abstract) && is_string($concrete)) {
                    $abstract = $concrete;
                }

                if (is_string($abstract) && is_string($concrete)) {
                    $this->binding($tree, $kind, $abstract, $concrete, 'certain');
                }
            }
        }
    }

    /** @param  ReflectionClass<object>  $reflection */
    protected function call(Expr $expr, ReflectionClass $reflection, TreeBuilder $tree): void
    {
        match (true) {
            $expr instanceof MethodCall => $this->methodCall($expr, $reflection, $tree),
            $expr instanceof StaticCall => $this->staticCall($expr, $tree),
            $expr instanceof FuncCall => $this->functionCall($expr, $tree),
            default => null,
        };
    }

    /** @param  ReflectionClass<object>  $reflection */
    protected function methodCall(MethodCall $call, ReflectionClass $reflection, TreeBuilder $tree): void
    {
        if (! $call->name instanceof Identifier) {
            return;
        }

        $name = $call->name->toString();

        // The end of a `when()->needs()->give()` chain is called on `needs()`,
        // not on the container — contextual() walks back to check it started
        // there.
        if ($name === 'give') {
            $this->contextual($call, $tree);

            return;
        }

        if ($this->isContainer($call->var)) {
            $this->containerCall($name, $call, $tree);

            return;
        }

        if ($call->var instanceof Variable && $call->var->name === 'this') {
            $this->providerCall($name, $call, $reflection, $tree);
        }
    }

    protected function staticCall(StaticCall $call, TreeBuilder $tree): void
    {
        if (! $call->class instanceof Name || ! $call->name instanceof Identifier) {
            return;
        }

        $class = $call->class->toString();

        if ($class === self::APP_FACADE) {
            $this->containerCall($call->name->toString(), $call, $tree);

            return;
        }

        if (isset(self::FACADE_EFFECTS[$class])) {
            $tree->sideEffect(self::FACADE_EFFECTS[$class]);
        }
    }

    /** `resolve(X::class)` and `app(X::class)` — `app()` bare is the container itself. */
    protected function functionCall(FuncCall $call, TreeBuilder $tree): void
    {
        if (! $call->name instanceof Name) {
            return;
        }

        if (in_array($call->name->toLowerString(), ['app', 'resolve'], true) && $call->args !== []) {
            $this->resolution($call, $tree);
        }
    }

    protected function containerCall(string $name, MethodCall|StaticCall $call, TreeBuilder $tree): void
    {
        match (true) {
            isset(self::BINDINGS[$name]) => $this->bindingCall(self::BINDINGS[$name], $call, $tree),
            in_array($name, self::RESOLUTIONS, true) => $this->resolution($call, $tree),
            $name === 'register' => $this->registration($call, $tree),
            default => null,
        };
    }

    /**
     * A call on the provider itself: a known side effect, or a helper method.
     *
     * A helper the application wrote is where bindings go to hide, so the call
     * is drawn as an arrow to an unresolved node rather than ignored. Methods the
     * framework's own base class supplies are not helpers in that sense.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    protected function providerCall(string $name, MethodCall $call, ReflectionClass $reflection, TreeBuilder $tree): void
    {
        if (isset(self::PROVIDER_EFFECTS[$name])) {
            $tree->sideEffect(self::PROVIDER_EFFECTS[$name]);

            return;
        }

        if (! $reflection->hasMethod($name)) {
            return;
        }

        $declaring = (new ReflectionMethod($reflection->getName(), $name))->getDeclaringClass()->getName();

        if (str_starts_with($declaring, 'Illuminate\\')) {
            return;
        }

        $tree->edge(
            $tree->current(),
            $tree->unresolved($this->print($call), 1),
            EdgeKind::Calls,
            'unknown',
        );
    }

    /** `bind(A, B)` and its siblings, as written at a call site. */
    protected function bindingCall(EdgeKind $kind, MethodCall|StaticCall $call, TreeBuilder $tree): void
    {
        $abstract = $this->literal($call->args[0] ?? null);

        if ($abstract === null) {
            $this->unreadable($tree, $kind, $call);

            return;
        }

        $argument = $call->args[1] ?? null;
        $value = $argument instanceof Arg ? $argument->value : null;

        // `instance()` hands over an object, so the concrete is only knowable
        // from a `new` written right there.
        if ($kind === EdgeKind::Instance) {
            $concrete = $value instanceof New_ && $value->class instanceof Name ? $value->class->toString() : null;
            $this->binding($tree, $kind, $abstract, $concrete, $concrete === null ? 'unknown' : 'certain', $value);

            return;
        }

        // No concrete, or null: the name is bound to itself.
        if ($value === null || $this->isNull($value)) {
            $this->binding($tree, $kind, $abstract, $abstract, 'certain');

            return;
        }

        $concrete = $this->literal($argument);

        if ($concrete !== null) {
            $this->binding($tree, $kind, $abstract, $concrete, 'certain');

            return;
        }

        // A closure that does nothing but construct one class is saying which
        // class, though not as a fact the container could check.
        $constructed = $this->constructedBy($value);

        $this->binding($tree, $kind, $abstract, $constructed, $constructed === null ? 'unknown' : 'inferred', $value);
    }

    /**
     * Provider → contract → concrete, or provider → class when a class is bound
     * to itself.
     */
    protected function binding(
        TreeBuilder $tree,
        EdgeKind $kind,
        string $abstract,
        ?string $concrete,
        string $confidence,
        ?Expr $unread = null,
        ?string $consumer = null,
    ): void {
        if ($concrete === $abstract) {
            $id = $tree->named($abstract, $tree->kindOf($abstract), 1, $confidence);
            $tree->edge($tree->current(), $id, $kind, $confidence, $consumer);

            return;
        }

        // The abstract was written literally, so it is certain even when what
        // it is bound to is not.
        $contract = $tree->named($abstract, NodeKind::Contract, 1, 'certain');
        $tree->edge($tree->current(), $contract, $kind, 'certain', $consumer);

        $target = $concrete === null
            ? $tree->unresolved($unread === null ? '?' : $this->print($unread), 2)
            : $tree->named($concrete, $tree->kindOf($concrete), 2, $confidence);

        $tree->edge($contract, $target, $kind, $confidence, $consumer);
    }

    /**
     * `when(Consumer)->needs(Contract)->give(Concrete)`.
     *
     * Found from its last link, since `give()` is the call that completes it —
     * a `when()->needs()` never finished binds nothing.
     */
    protected function contextual(MethodCall $give, TreeBuilder $tree): void
    {
        $needs = $give->var;
        $when = $needs instanceof MethodCall ? $needs->var : null;

        if (! $needs instanceof MethodCall || ! $this->isCall($needs, 'needs')
            || ! $when instanceof MethodCall || ! $this->isCall($when, 'when')
            || ! $this->isContainer($when->var)) {
            return;
        }

        $consumers = $this->literals($when->args[0] ?? null);
        $abstract = $this->literal($needs->args[0] ?? null);

        if ($consumers === null || $abstract === null) {
            $this->unreadable($tree, EdgeKind::Contextual, $give);

            return;
        }

        $argument = $give->args[0] ?? null;
        $value = $argument instanceof Arg ? $argument->value : null;
        $concrete = $this->literal($argument);
        $confidence = 'certain';

        if ($concrete === null) {
            $concrete = $value === null ? null : $this->constructedBy($value);
            $confidence = $concrete === null ? 'unknown' : 'inferred';
        }

        foreach ($consumers as $consumer) {
            $this->binding($tree, EdgeKind::Contextual, $abstract, $concrete, $confidence, $value, $consumer);
        }
    }

    /**
     * `$this->app->register(Other::class)` — an edge to a provider, and that
     * provider's own bindings beneath it.
     *
     * Registering a provider is binding everything it binds, so stopping at a
     * node named after it would hide exactly what the call does. Only the
     * application's own providers are followed; a package's provider is a
     * leaf, for the same reason vendor code is everywhere else.
     */
    protected function registration(MethodCall|StaticCall $call, TreeBuilder $tree): void
    {
        $provider = $this->literal($call->args[0] ?? null);

        if ($provider === null) {
            $this->unreadable($tree, EdgeKind::Registers, $call);

            return;
        }

        $id = $tree->named($provider, NodeKind::Provider, 1, 'certain');
        $tree->edge($tree->current(), $id, EdgeKind::Registers, 'certain');

        if ($tree->node($id)?->origin !== Origin::App || $tree->kindOf($id) !== NodeKind::Provider) {
            return;
        }

        $tree->within($id, fn () => $this->read(new ReflectionClass($id), $tree));
    }

    /** `make()`, `resolve()`, `app()` with a name: needed, not decided. */
    protected function resolution(MethodCall|StaticCall|FuncCall $call, TreeBuilder $tree): void
    {
        $name = $this->literal($call->args[0] ?? null);

        if ($name === null) {
            $this->unreadable($tree, EdgeKind::Resolves, $call);

            return;
        }

        $tree->edge($tree->current(), $tree->named($name, $tree->kindOf($name), 1, 'certain'), EdgeKind::Resolves, 'certain');
    }

    /** A call that was seen and could not be read: one unresolved node, labelled with what was written. */
    protected function unreadable(TreeBuilder $tree, EdgeKind $kind, Expr $call): void
    {
        $tree->edge($tree->current(), $tree->unresolved($this->print($call), 1), $kind, 'unknown');
    }

    /**
     * What a deferred provider promises, from the array `provides()` returns.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return array<int, string>
     */
    protected function provides(ReflectionClass $reflection): array
    {
        // ClassSource stops at Illuminate's base class, whose `provides()` is an
        // empty array anyway.
        $method = $this->source->method($reflection->getName(), 'provides');
        $array = $method === null ? null : $this->source->returnedArray($method);

        $provides = [];

        foreach ($array === null ? [] : $array->items as $item) {
            $name = $item === null ? null : $this->literal(new Arg($item->value));

            if ($name !== null) {
                $provides[] = $name;
            }
        }

        return $provides;
    }

    /** `$this->app`, `app()`, or the `App` facade — the three ways a provider reaches the container. */
    protected function isContainer(Expr $expr): bool
    {
        if ($expr instanceof PropertyFetch) {
            return $expr->var instanceof Variable && $expr->var->name === 'this'
                && $expr->name instanceof Identifier && $expr->name->toString() === 'app';
        }

        return $expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toLowerString() === 'app'
            && $expr->args === [];
    }

    /** A class constant or a string, written right there. */
    protected function literal(mixed $argument): ?string
    {
        if (! $argument instanceof Arg) {
            return null;
        }

        $value = $argument->value;

        if ($value instanceof ClassConstFetch
            && $value->class instanceof Name
            && $value->name instanceof Identifier
            && $value->name->toLowerString() === 'class') {
            return ltrim($value->class->toString(), '\\');
        }

        return $value instanceof String_ ? $value->value : null;
    }

    /**
     * One literal, or an array of them — `when()` accepts either.
     *
     * @return array<int, string>|null
     */
    protected function literals(mixed $argument): ?array
    {
        if ($argument instanceof Arg && $argument->value instanceof Expr\Array_) {
            $names = [];

            foreach ($argument->value->items as $item) {
                $name = $item === null ? null : $this->literal(new Arg($item->value));

                if ($name === null) {
                    return null;
                }

                $names[] = $name;
            }

            return $names;
        }

        $name = $this->literal($argument);

        return $name === null ? null : [$name];
    }

    /** The class a closure constructs, when constructing it is all the closure does. */
    protected function constructedBy(Expr $expr): ?string
    {
        $returned = match (true) {
            $expr instanceof ArrowFunction => $expr->expr,
            $expr instanceof Closure && count($expr->stmts) === 1 && $expr->stmts[0] instanceof Return_ => $expr->stmts[0]->expr,
            default => null,
        };

        return $returned instanceof New_ && $returned->class instanceof Name
            ? ltrim($returned->class->toString(), '\\')
            : null;
    }

    protected function isCall(MethodCall $call, string $name): bool
    {
        return $call->name instanceof Identifier && $call->name->toString() === $name;
    }

    protected function isNull(Expr $expr): bool
    {
        return $expr instanceof Expr\ConstFetch && $expr->name->toLowerString() === 'null';
    }

    /** What was written, short enough to fit on a card. */
    protected function print(Expr $expr): string
    {
        $printed = preg_replace('/\s+/', ' ', $this->printer->prettyPrintExpr($expr)) ?? '';

        return mb_strlen($printed) > 80 ? mb_substr($printed, 0, 79).'…' : $printed;
    }
}
