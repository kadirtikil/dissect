<?php

namespace KdrDev\Dissect\Jobs;

use KdrDev\Dissect\Routes\Ast\ClassSource;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Finds where each job is put on the queue.
 *
 * This is the field the job list exists for. A job class on its own is a
 * definition; what somebody actually needs to know is which endpoint, which
 * command, or which other job causes it to run — and for the endpoint case
 * dissect already holds the route table, so the answer is a link rather than a
 * file path.
 *
 * **Two shapes, one rule.** Laravel offers a great many ways to queue
 * something: `Job::dispatch()`, `dispatch(new Job)`, `Bus::batch([...])`,
 * `->chain([...])`, `Mail::to($u)->queue(new Mailable)`, `$user->notify(new
 * Notification)`. Enumerating those APIs would mean missing the next one, so
 * only two node shapes are looked for — a static `dispatch*()` call, and a
 * `new` — and the result is kept **only when the class is one discovery
 * already found**. That is {@see \KdrDev\Dissect\Routes\ModelLinker}'s
 * discipline applied to call sites: accept a match against the set of things
 * that exist, rather than guessing from the shape of the call.
 *
 * The cost of the rule is that constructing a queueable without dispatching it
 * reads as a dispatch. For a class that implements `ShouldQueue`, that is
 * overwhelmingly a dispatch through an API this deliberately does not
 * enumerate.
 */
class DispatchScanner
{
    /**
     * Static calls that queue the class they are called on.
     *
     * `dispatchSync` and `dispatchAfterResponse` do not touch a queue at all —
     * they are listed because somebody reading "where does this job run from"
     * needs to see them, and seeing them described as synchronous is the point.
     */
    protected const DISPATCH_METHODS = [
        'dispatch',
        'dispatchIf',
        'dispatchUnless',
        'dispatchSync',
        'dispatchNow',
        'dispatchAfterResponse',
    ];

    /** Chained onto a pending dispatch, and worth reporting when they are. */
    protected const MODIFIERS = ['onQueue', 'onConnection', 'delay', 'afterCommit'];

    /** @param  array<int, string>  $paths */
    public function __construct(
        protected ClassSource $source,
        protected array $paths = [],
    ) {}

    /**
     * Dispatch sites for each of `$known`, keyed by class.
     *
     * A class with no entry is not "never dispatched" — it is "no dispatch site
     * was found", which is a different claim, and the surface says so.
     *
     * @param  array<int, string>  $known
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function sites(array $known): array
    {
        $known = array_flip(array_map(fn (string $class) => ltrim($class, '\\'), $known));
        $sites = [];

        foreach ($this->files() as $file) {
            foreach ($this->inFile($file->getPathname(), $known) as $class => $found) {
                $sites[$class] = [...$sites[$class] ?? [], ...$found];
            }
        }

        foreach ($sites as $class => $found) {
            // Grouped by file, then by position within it, so the list reads in
            // the order somebody would open the files.
            usort($found, fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

            $sites[$class] = $found;
        }

        return $sites;
    }

    /**
     * @param  array<string, int>  $known
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function inFile(string $path, array $known): array
    {
        $statements = $this->source->file($path);

        if ($statements === null) {
            return [];
        }

        $finder = new NodeFinder;
        $sites = [];

        foreach ($finder->findInstanceOf($statements, StaticCall::class) as $call) {
            /** @var StaticCall $call */
            $class = $this->nameOf($call->class);

            if ($class === null || ! isset($known[$class]) || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            if (! in_array($call->name->toString(), self::DISPATCH_METHODS, true)) {
                continue;
            }

            $sites[$class][] = $this->describe($call, $path, $call->name->toString());
        }

        foreach ($finder->findInstanceOf($statements, New_::class) as $new) {
            /** @var New_ $new */
            $class = $this->nameOf($new->class);

            if ($class === null || ! isset($known[$class])) {
                continue;
            }

            $sites[$class][] = $this->describe($new, $path, $this->callAround($new) ?? 'new');
        }

        return $sites;
    }

    /**
     * One site: where it is, what it sits inside, and how it was queued.
     *
     * @return array<string, mixed>
     */
    protected function describe(Node $node, string $path, string $method): array
    {
        $context = $this->contextOf($node, $path);
        $modifiers = $this->modifiers($node);

        return [
            'file' => $this->relative($path),
            'line' => $node->getStartLine(),
            'label' => $context['label'],
            // The key the route table is joined on. Unlike the label it is
            // fully qualified, because two controllers in different namespaces
            // can share a basename and must not share a link.
            'context' => $context['context'],
            'method' => $method,
            // Only ever a literal. `->onQueue($this->tenant->queue)` names a
            // queue that is not knowable without running the code.
            'queue' => $modifiers['onQueue'] ?? null,
            'connection' => $modifiers['onConnection'] ?? null,
            'delayed' => array_key_exists('delay', $modifiers),
            'afterCommit' => array_key_exists('afterCommit', $modifiers),
            // Filled in by the exporter, which is the only thing here that
            // knows about routes.
            'route' => null,
        ];
    }

    /**
     * What the site sits inside — a controller action, a closure, or neither.
     *
     * `label` is for reading and matches how the routes surface labels the same
     * action. `context` is for joining, and addresses a closure the way
     * {@see \KdrDev\Dissect\Routes\ActionResolver} does: by where it is written,
     * which is the only identity a closure has.
     *
     * @return array{label: string, context: string}
     */
    protected function contextOf(Node $node, string $path): array
    {
        $method = null;
        $class = null;
        $closure = null;

        for ($current = $node; $current !== null; $current = $current->getAttribute('parent')) {
            if ($closure === null && ($current instanceof Closure || $current instanceof Function_)) {
                $closure = $current;
            }

            if ($method === null && $current instanceof ClassMethod) {
                $method = $current;
            }

            if ($current instanceof Class_) {
                $class = $current;

                break;
            }
        }

        // A closure nested inside a method belongs to the method: that is the
        // action somebody would look for. A closure with no method around it is
        // route-file code, and its own position is its name.
        if ($class !== null && $method !== null) {
            $name = $class->namespacedName?->toString() ?? $class->name?->toString() ?? '';
            $called = $method->name->toString();
            $suffix = $called === '__invoke' ? '' : '@'.$called;

            return [
                'label' => class_basename($name).$suffix,
                'context' => $name.'@'.$called,
            ];
        }

        $line = ($closure ?? $node)->getStartLine();

        return [
            'label' => basename($path).':'.$line,
            'context' => $this->relative($path).':'.$line,
        ];
    }

    /**
     * The `->onQueue(...)`-style calls chained onto a pending dispatch.
     *
     * Read by walking back up the tree, because the chain wraps the dispatch
     * rather than following it: `Job::dispatch($x)->onQueue('mail')` parses as
     * a method call whose subject is the static call. Only calls the site is
     * the *subject* of are followed — an argument that happens to be a
     * dispatch is not modified by the call it is passed to.
     *
     * @return array<string, string|null>
     */
    protected function modifiers(Node $node): array
    {
        $modifiers = [];
        $current = $node;

        while (($parent = $current->getAttribute('parent')) instanceof MethodCall) {
            if ($parent->var !== $current) {
                break;
            }

            if ($parent->name instanceof Node\Identifier
                && in_array($name = $parent->name->toString(), self::MODIFIERS, true)) {
                $argument = $parent->args[0] ?? null;

                $modifiers[$name] = $argument instanceof Arg && $argument->value instanceof String_
                    ? $argument->value->value
                    : null;
            }

            $current = $parent;
        }

        return $modifiers;
    }

    /**
     * The name of the call a `new` is an argument to — `queue`, `notify`,
     * `batch`, `chain`, `send`.
     *
     * This is what turns "constructed here" into something that reads like what
     * it is, without this class having to know the API it came from.
     */
    protected function callAround(Node $node): ?string
    {
        $argument = $node->getAttribute('parent');

        if (! $argument instanceof Arg && ! $argument instanceof Node\ArrayItem) {
            return null;
        }

        // `Bus::batch([new A, new B])` — the array is the argument, so the call
        // is one level further up.
        for ($current = $argument; $current !== null; $current = $current->getAttribute('parent')) {
            if ($current instanceof MethodCall || $current instanceof StaticCall || $current instanceof FuncCall) {
                $name = $current->name;

                return $name instanceof Node\Identifier || $name instanceof Name
                    ? class_basename($name->toString())
                    : null;
            }

            if ($current instanceof ClassMethod || $current instanceof Closure) {
                return null;
            }
        }

        return null;
    }

    /** The resolved name behind a `new X` or `X::dispatch()`, if it is a name at all. */
    protected function nameOf(mixed $class): ?string
    {
        if ($class instanceof Name) {
            return ltrim($class->toString(), '\\');
        }

        // `$class::dispatch()` and `new $class` name something only the running
        // application knows.
        if ($class instanceof ClassConstFetch && $class->class instanceof Name) {
            return ltrim($class->class->toString(), '\\');
        }

        return null;
    }

    /** @return iterable<SplFileInfo> */
    protected function files(): iterable
    {
        $directories = array_values(array_filter(
            array_map(ProjectPath::absolute(...), $this->paths),
            is_dir(...),
        ));

        if ($directories === []) {
            return [];
        }

        return (new Finder)->in($directories)->files()->name('*.php');
    }

    /** Shared with {@see RouteMap}, which has to spell a closure's address identically. */
    protected function relative(string $path): string
    {
        return ProjectPath::relative($path);
    }
}
