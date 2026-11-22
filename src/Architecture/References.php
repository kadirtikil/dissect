<?php

namespace KdrDev\Dissect\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Every class each declaration in a file refers to, and how.
 *
 * Runs after a NameResolver pass, so every name arrives fully qualified. It
 * reads syntax only: a reference is somewhere the code *names* a class, which
 * is what a boundary rule is about and what can be known without running
 * anything.
 *
 * Most kinds are what the syntax says (`new`, `extends`, a type hint). Four
 * are what the syntax means in a Laravel app, and are what a generic
 * dependency tool cannot see: a relation method's target, a job or event
 * being dispatched, and a container binding. A name gets exactly one kind,
 * the most specific one that applies.
 */
final class References extends NodeVisitorAbstract
{
    /** The kinds, most to least specific within each family. */
    public const KINDS = [
        'extends', 'implements', 'trait',
        'relation', 'dispatch', 'event', 'binding',
        'new', 'static', 'type', 'instanceof', 'catch', 'attribute',
    ];

    /** Eloquent's relation builders: the class each is given is the related model. */
    protected const RELATIONS = [
        'hasone', 'hasmany', 'belongsto', 'belongstomany', 'morphone', 'morphmany',
        'morphtomany', 'morphedbymany', 'hasonethrough', 'hasmanythrough',
    ];

    /** Static calls that put the class they are called on onto the queue or the bus. */
    protected const DISPATCHES = ['dispatch', 'dispatchif', 'dispatchunless', 'dispatchsync', 'dispatchnow', 'dispatchafterresponse'];

    /** Helpers whose `new X` argument is sent somewhere rather than just built. */
    protected const SENDERS = [
        'dispatch' => 'dispatch', 'dispatch_sync' => 'dispatch', 'dispatch_now' => 'dispatch',
        'event' => 'event', 'broadcast' => 'event',
    ];

    /** Container methods whose class arguments are a binding. */
    protected const BINDINGS = [
        'bind', 'bindif', 'singleton', 'singletonif', 'scoped', 'scopedif',
        'instance', 'extend', 'alias', 'tag', 'when', 'needs', 'give',
    ];

    /** Where a kind is stashed on a node an enclosing call has already classified. */
    protected const MARK = 'dissect.kind';

    /**
     * Each named declaration in the file, in order.
     *
     * @var array<int, array{id: string, kind: string, abstract: bool, line: int, uses: array<string, array<string, array<int, int>>>}>
     */
    public array $declarations = [];

    /** @var array<int, int> Indexes into $declarations, innermost last. */
    protected array $open = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $this->declarations[] = [
                'id' => $node->namespacedName?->toString() ?? $node->name->toString(),
                'kind' => match (true) {
                    $node instanceof Stmt\Interface_ => 'interface',
                    $node instanceof Stmt\Trait_ => 'trait',
                    $node instanceof Stmt\Enum_ => 'enum',
                    default => 'class',
                },
                'abstract' => $node instanceof Stmt\Interface_ || ($node instanceof Stmt\Class_ && $node->isAbstract()),
                'line' => $node->getStartLine(),
                'uses' => [],
            ];
            $this->open[] = array_key_last($this->declarations);
        }

        if ($this->open === []) {
            return null;
        }

        $this->classify($node);
        $this->collect($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassLike && $node->name !== null) {
            array_pop($this->open);
        }

        return null;
    }

    /**
     * Marks the class arguments of a call whose meaning is more than its syntax.
     *
     * The call is entered before its arguments, so the mark is there by the
     * time the argument itself is collected.
     */
    protected function classify(Node $node): void
    {
        $kind = match (true) {
            $node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier => $this->methodKind($node),
            $node instanceof Expr\FuncCall && $node->name instanceof Name => self::SENDERS[strtolower($node->name->getLast())] ?? null,
            $node instanceof Expr\StaticCall && $node->class instanceof Name && $node->name instanceof Node\Identifier => $this->facadeKind($node),
            default => null,
        };

        if ($kind === null) {
            return;
        }

        foreach ($node->getArgs() as $arg) {
            $value = $arg->value;

            if (($value instanceof Expr\ClassConstFetch || $value instanceof Expr\New_) && $value->class instanceof Name) {
                $value->setAttribute(self::MARK, $kind);
            }
        }
    }

    protected function methodKind(Expr\MethodCall $call): ?string
    {
        $method = strtolower($call->name->toString());

        if (in_array($method, self::RELATIONS, true) && $call->var instanceof Expr\Variable && $call->var->name === 'this') {
            return 'relation';
        }

        if (in_array($method, self::BINDINGS, true) && $this->isContainer($call->var)) {
            return 'binding';
        }

        // `$this->dispatch(new X)`, `Bus::chain(...)->dispatch(...)`, a
        // dispatcher held in a property: whatever is handed in is sent.
        return $method === 'dispatch' ? 'dispatch' : null;
    }

    /** `Event::dispatch(new X)` sends an event; `Bus::dispatch(new X)` a job. */
    protected function facadeKind(Expr\StaticCall $call): ?string
    {
        $method = strtolower($call->name->toString());

        if (! in_array($method, self::DISPATCHES, true)) {
            return null;
        }

        return match ($call->class->toString()) {
            'Illuminate\Support\Facades\Event' => 'event',
            'Illuminate\Support\Facades\Bus', 'Illuminate\Support\Facades\Queue' => 'dispatch',
            default => null,
        };
    }

    /** `$this->app`, `app()`, `$app`, or a `when()` chain hanging off one of them. */
    protected function isContainer(Expr $expr): bool
    {
        while ($expr instanceof Expr\MethodCall) {
            $expr = $expr->var;
        }

        return match (true) {
            $expr instanceof Expr\PropertyFetch => $expr->name instanceof Node\Identifier && $expr->name->toString() === 'app',
            $expr instanceof Expr\FuncCall => $expr->name instanceof Name && $expr->name->getLast() === 'app',
            $expr instanceof Expr\Variable => in_array($expr->name, ['app', 'container'], true),
            default => false,
        };
    }

    protected function collect(Node $node): void
    {
        $marked = $node->getAttribute(self::MARK);

        match (true) {
            $node instanceof Stmt\Class_ && $node->name === null => $this->add($node->extends, 'new', $node),
            $node instanceof Stmt\Class_ => $this->addAll([$node->extends, ...$node->implements], ['extends', ...array_fill(0, count($node->implements), 'implements')], $node),
            $node instanceof Stmt\Interface_ => $this->addAll($node->extends, array_fill(0, count($node->extends), 'extends'), $node),
            $node instanceof Stmt\Enum_ => $this->addAll($node->implements, array_fill(0, count($node->implements), 'implements'), $node),
            $node instanceof Stmt\TraitUse => $this->addAll($node->traits, array_fill(0, count($node->traits), 'trait'), $node),
            $node instanceof Expr\New_ => $this->add($node->class, $marked ?? 'new', $node),
            $node instanceof Expr\StaticCall => $this->add($node->class, $this->staticKind($node), $node),
            $node instanceof Expr\StaticPropertyFetch => $this->add($node->class, 'static', $node),
            $node instanceof Expr\ClassConstFetch => $this->add($node->class, $marked ?? 'static', $node),
            $node instanceof Expr\Instanceof_ => $this->add($node->class, 'instanceof', $node),
            $node instanceof Stmt\Catch_ => $this->addAll($node->types, array_fill(0, count($node->types), 'catch'), $node),
            $node instanceof Node\Attribute => $this->add($node->name, 'attribute', $node),
            $node instanceof Node\Param, $node instanceof Stmt\Property, $node instanceof Stmt\ClassConst => $this->type($node->type, $node),
            $node instanceof Node\FunctionLike => $this->type($node->getReturnType(), $node),
            default => null,
        };
    }

    protected function staticKind(Expr\StaticCall $call): string
    {
        return $call->name instanceof Node\Identifier && in_array(strtolower($call->name->toString()), self::DISPATCHES, true)
            ? 'dispatch'
            : 'static';
    }

    /** Every class a type declaration names: `?A`, `A|B`, `A&B`, `(A&B)|null`. */
    protected function type(mixed $type, Node $at): void
    {
        if ($type instanceof Node\NullableType) {
            $this->type($type->type, $at);
        } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $part) {
                $this->type($part, $at);
            }
        } elseif ($type instanceof Name) {
            $this->add($type, 'type', $at);
        }
    }

    /**
     * @param  array<int, mixed>  $names
     * @param  array<int, string>  $kinds
     */
    protected function addAll(array $names, array $kinds, Node $at): void
    {
        foreach ($names as $i => $name) {
            $this->add($name, $kinds[$i], $at);
        }
    }

    protected function add(mixed $name, string $kind, Node $at): void
    {
        // A variable class (`new $class`), `self`, `static` and `parent` name
        // nothing this can follow.
        if (! $name instanceof Name || $name->isSpecialClassName()) {
            return;
        }

        $to = $name->toString();
        $index = end($this->open);

        if ($to === $this->declarations[$index]['id']) {
            return;
        }

        $line = $at->getStartLine();
        $lines = &$this->declarations[$index]['uses'][$to][$kind];
        $lines ??= [];

        if (! in_array($line, $lines, true)) {
            $lines[] = $line;
        }
    }
}
