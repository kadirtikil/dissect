<?php

namespace KdrDev\Dissect\Jobs;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use KdrDev\Dissect\Routes\Ast\ClassSource;
use KdrDev\Dissect\Routes\ModelLinker;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use Throwable;

/**
 * Everything one queueable class says about itself.
 *
 * A job declares how it should be run — which queue, how many attempts, how
 * long between them — as properties, which reflection reads exactly. The two
 * that can also be written as *methods* (`backoff()` and `middleware()`) are
 * read from source instead, never called: a `middleware()` returning
 * `new WithoutOverlapping($this->invoice->id)` on an object nobody constructed
 * would fatal, and describing a job must not be able to.
 *
 * The link to the rest of dissect is `payload`. A job's constructor signature
 * is its payload, and a type-hinted model there is the same node id the graph
 * draws — so "what does this job carry" and "what does this table mean" are one
 * click apart.
 */
class JobInspector
{
    /** Attempt limits and timings, read straight off the class. */
    protected const RETRY_PROPERTIES = ['tries', 'timeout', 'maxExceptions'];

    /** Constructor calls that decide where the job runs. */
    protected const ROUTING_CALLS = ['onQueue', 'onConnection'];

    /** @var array<class-string, array<int, string>>|null listener class => events */
    protected ?array $listeners = null;

    public function __construct(
        protected ClassSource $source,
        protected ModelLinker $models,
        protected Dispatcher $events,
    ) {}

    /**
     * One queueable class, or null if it cannot be reflected at all.
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $class): ?array
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return null;
        }

        // Confidence is accumulated as the harder fields are read, so the value
        // reported is the weakest of them — the same ladder the request and
        // response shapes are graded on.
        $confidence = new Confidence;

        $defaults = $reflection->getDefaultProperties();
        $routing = $this->routing($reflection, $defaults);

        return [
            'id' => $class,
            'class' => $class,
            'name' => class_basename($class),
            'kind' => $this->kind($class),
            'queue' => $routing['queue'],
            // Upgraded to `dispatch` by the exporter when the class itself named
            // no queue but a dispatch site did.
            'queue_source' => $routing['queue_source'],
            'connection' => $routing['connection'],
            'retry' => $this->retry($reflection, $defaults, $confidence),
            'traits' => $this->traits($class, $reflection, $defaults),
            'payload' => $this->payload($reflection),
            'middleware' => $this->middleware($reflection, $confidence),
            'events' => $this->listeners()[ltrim($class, '\\')] ?? [],
            'confidence' => $confidence->value(),
        ];
    }

    /**
     * Which queue and connection the class itself asks for.
     *
     * There are two spellings and only one of them is a property. A job that
     * uses `Queueable` **cannot** declare `public $queue` — PHP rejects it as
     * an incompatible redefinition of the trait's own property — so the way
     * almost every job names its queue is `$this->onQueue('…')` in the
     * constructor. Reading only the property would report "default" for the
     * majority of real jobs, which is not a smaller answer, it is a wrong one.
     *
     * Listeners, mailables and notifications inherit those properties from a
     * class rather than a trait, so for them the property is the usual form.
     * Both are read, and which one answered is reported.
     *
     * @param  array<string, mixed>  $defaults
     * @return array{queue: string|null, queue_source: string, connection: string|null}
     */
    protected function routing(ReflectionClass $reflection, array $defaults): array
    {
        $queue = $this->stringOrNull($defaults['queue'] ?? null);
        $connection = $this->stringOrNull($defaults['connection'] ?? null);

        if ($queue !== null) {
            return ['queue' => $queue, 'queue_source' => 'property', 'connection' => $connection];
        }

        $constructed = $this->constructorRouting($reflection);

        return [
            'queue' => $constructed['onQueue'] ?? null,
            'queue_source' => isset($constructed['onQueue']) ? 'constructor' : 'default',
            'connection' => $connection ?? $constructed['onConnection'] ?? null,
        ];
    }

    /**
     * `$this->onQueue('reports')` written in the constructor.
     *
     * Only the constructor's **own** statements are read, never a call nested
     * inside a condition. A queue chosen by an `if` is a queue that depends on
     * the arguments, and reporting one branch of it as the answer would be a
     * confident lie — the kind `confidence` exists to prevent, except that here
     * the honest thing is simply not to claim a queue at all.
     *
     * @return array<string, string>
     */
    protected function constructorRouting(ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        // The declaring class, not the inspected one: a base job that sets the
        // queue for everything extending it is exactly where this is written.
        $method = $this->source->method($constructor->getDeclaringClass()->getName(), '__construct');

        $calls = [];

        foreach ($method?->stmts ?? [] as $statement) {
            // Anything that is not ultimately a call on $this describes some
            // other object and says nothing about this job.
            if (! $statement instanceof Expression || ! $this->targetsThis($statement->expr)) {
                continue;
            }

            // `$this->onQueue('a')->delay(60)` is one statement and several
            // calls, so the whole chain is walked back to `$this`.
            for ($call = $statement->expr; $call instanceof MethodCall; $call = $call->var) {
                if (! $call->name instanceof Identifier) {
                    continue;
                }

                $name = $call->name->toString();
                $argument = $call->args[0] ?? null;

                if (in_array($name, self::ROUTING_CALLS, true)
                    && $argument instanceof Arg
                    && $argument->value instanceof String_) {
                    // First write wins, matching the order the constructor runs.
                    $calls[$name] ??= $argument->value->value;
                }
            }
        }

        return $calls;
    }

    /** Whether a call chain bottoms out at `$this`. */
    protected function targetsThis(Node $expr): bool
    {
        for ($current = $expr; $current instanceof MethodCall; $current = $current->var) {
            if ($current->var instanceof Variable && $current->var->name === 'this') {
                return true;
            }
        }

        return false;
    }

    /**
     * Which of the four queueable shapes this is.
     *
     * Decided by what the class *is*, never by which configured directory it
     * turned up in: a mailable kept somewhere other than `app/Mail` is still a
     * mailable, and the facet would be a lie if it reported otherwise.
     *
     * Parent class names are compared as strings because `illuminate/mail` and
     * `illuminate/notifications` are not dependencies of this package. An
     * application that does not have them installed simply has no class that
     * matches.
     */
    protected function kind(string $class): string
    {
        $parents = class_parents($class) ?: [];

        if (isset($parents['Illuminate\Mail\Mailable'])) {
            return 'mailable';
        }

        if (isset($parents['Illuminate\Notifications\Notification'])) {
            return 'notification';
        }

        // A listener is the one shape with no marker of its own — what makes it
        // a listener is that something registered it against an event. The
        // dispatcher is the only place that is written down.
        return isset($this->listeners()[ltrim($class, '\\')]) ? 'listener' : 'job';
    }

    /**
     * How hard the worker will try, and how long it will wait.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    protected function retry(ReflectionClass $reflection, array $defaults, Confidence $confidence): array
    {
        $retry = [];

        foreach (self::RETRY_PROPERTIES as $property) {
            $value = $defaults[$property] ?? null;
            $retry[$property] = is_int($value) ? $value : null;
        }

        return $retry + [
            'backoff' => $this->backoff($reflection, $defaults, $confidence),
            // Not the deadline itself: `retryUntil()` returns a time computed
            // when the job is queued, so there is no value to report here —
            // only whether the job sets one, which changes how `tries` reads.
            'retryUntil' => $reflection->hasMethod('retryUntil'),
        ];
    }

    /**
     * Seconds between attempts, as a list.
     *
     * Laravel accepts three spellings — one int, a list of ints, or a method
     * returning either — and they mean the same thing to the worker, so they
     * are reported the same way here. The method form is *read*, since calling
     * it can reach for a property no constructor has set.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<int, int>|null
     */
    protected function backoff(ReflectionClass $reflection, array $defaults, Confidence $confidence): ?array
    {
        $value = $defaults['backoff'] ?? null;

        if (is_int($value)) {
            return [$value];
        }

        if (is_array($value)) {
            return array_values(array_filter($value, is_int(...)));
        }

        if (! $reflection->hasMethod('backoff')) {
            return null;
        }

        $method = $this->source->method($reflection->getName(), 'backoff');
        $array = $method === null ? null : $this->source->returnedArray($method);

        if ($array !== null) {
            $confidence->inferred();

            $seconds = [];

            foreach ($array->items as $item) {
                if ($item?->value instanceof Int_) {
                    $seconds[] = $item->value->value;
                }
            }

            return $seconds;
        }

        // A bare `return 30;` rather than a list — still worth reporting.
        foreach ($method === null ? [] : $this->source->find($method, Return_::class) as $return) {
            /** @var Return_ $return */
            if ($return->expr instanceof Int_) {
                $confidence->inferred();

                return [$return->expr->value];
            }
        }

        // The method is there and its value could not be read. Saying so beats
        // reporting a job that retries immediately, which is what an empty
        // backoff would imply.
        $confidence->unknown();

        return null;
    }

    /**
     * The marker interfaces and traits that change how the job is handled.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, bool>
     */
    protected function traits(string $class, ReflectionClass $reflection, array $defaults): array
    {
        return [
            'batchable' => isset((class_uses_recursive($class) ?: [])['Illuminate\Bus\Batchable']),
            'unique' => $reflection->implementsInterface(ShouldBeUnique::class),
            'encrypted' => $reflection->implementsInterface(ShouldBeEncrypted::class),
            // Two ways of saying it, one meaning: hold the dispatch until the
            // surrounding transaction commits.
            'afterCommit' => $reflection->implementsInterface(ShouldQueueAfterCommit::class)
                || ($defaults['afterCommit'] ?? null) === true,
        ];
    }

    /**
     * What the job carries, read from its constructor.
     *
     * A queued job is its constructor arguments — that is literally what gets
     * serialised onto the queue — so the signature is the payload, and a model
     * among them is a link to the graph.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function payload(ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $payload = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $this->typeName($parameter->getType());

            $payload[] = [
                'name' => $parameter->getName(),
                'type' => $type,
                // Only ever set when the graph actually holds a node by that
                // name — ModelLinker's rule, not a guess made here.
                'model' => $this->models->forClass($type),
                'optional' => $parameter->isOptional(),
                'variadic' => $parameter->isVariadic(),
                'default' => $this->defaultOf($parameter),
            ];
        }

        return $payload;
    }

    /**
     * Job middleware, by class basename.
     *
     * Read, never run. `middleware()` commonly returns middleware constructed
     * from the job's own state — `new WithoutOverlapping($this->order->id)` —
     * which on an unconstructed instance is a fatal error rather than an
     * exception, and would take the page down with it.
     *
     * @return array<int, string>
     */
    protected function middleware(ReflectionClass $reflection, Confidence $confidence): array
    {
        if (! $reflection->hasMethod('middleware')) {
            return [];
        }

        $method = $this->source->method($reflection->getName(), 'middleware');
        $array = $method === null ? null : $this->source->returnedArray($method);

        if ($array === null) {
            $confidence->unknown();

            return [];
        }

        $confidence->inferred();

        $middleware = [];

        // Every `new X` in the returned literal, however it is nested — a
        // conditional list built with array unpacking still names its
        // middleware this way.
        foreach ($this->source->find($array, New_::class) as $new) {
            /** @var New_ $new */
            $name = $new->class instanceof Name ? $new->class->toString() : null;

            if ($name !== null) {
                $middleware[] = class_basename($name);
            }
        }

        return array_values(array_unique($middleware));
    }

    /**
     * Listener classes registered against events, and which events those are.
     *
     * Memoised: the map is the same for every class inspected in one export.
     *
     * @return array<string, array<int, string>>
     */
    protected function listeners(): array
    {
        if ($this->listeners !== null) {
            return $this->listeners;
        }

        $this->listeners = [];

        // getRawListeners() is Illuminate's own dispatcher, not the contract.
        // An application that has swapped it for something else still gets a
        // job list; what it loses is the `listener` facet.
        if (! method_exists($this->events, 'getRawListeners')) {
            return $this->listeners;
        }

        try {
            $raw = $this->events->getRawListeners();
        } catch (Throwable) {
            return $this->listeners;
        }

        foreach ($raw as $event => $registered) {
            if (! is_string($event)) {
                continue;
            }

            foreach ((array) $registered as $listener) {
                $class = $this->listenerClass($listener);

                if ($class !== null) {
                    $this->listeners[$class][] = $event;
                }
            }
        }

        foreach ($this->listeners as $class => $events) {
            $this->listeners[$class] = array_values(array_unique($events));
        }

        return $this->listeners;
    }

    /** `Class@method`, `Class`, or `[Class, 'method']` — a closure names nothing. */
    protected function listenerClass(mixed $listener): ?string
    {
        if (is_array($listener) && isset($listener[0])) {
            $listener = is_object($listener[0]) ? $listener[0]::class : $listener[0];
        }

        if (! is_string($listener) || $listener === '') {
            return null;
        }

        return ltrim(str_contains($listener, '@') ? strstr($listener, '@', true) : $listener, '\\');
    }

    protected function typeName(?ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        // A union or intersection type names several things, none of which is
        // "the" payload type, so it is reported verbatim and links to nothing.
        return $type === null ? null : (string) $type;
    }

    /** A scalar default worth showing; anything richer is reported as absent. */
    protected function defaultOf(ReflectionParameter $parameter): string|int|float|bool|null
    {
        try {
            $default = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        } catch (Throwable) {
            return null;
        }

        return is_scalar($default) ? $default : null;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
