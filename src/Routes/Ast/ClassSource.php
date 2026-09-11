<?php

namespace KdrDev\Dissect\Routes\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use Throwable;

/**
 * Reads method bodies as syntax rather than running them.
 *
 * Validation rules and resource shapes are written as array literals, which is
 * exactly the kind of thing that can be read from the source without side
 * effects — no container, no database, no chance of a `rules()` that expects a
 * live request bringing the page down.
 *
 * Every method here returns null on anything it cannot handle. A file that does
 * not parse is a file this package has no opinion about, not an error worth
 * showing somebody looking at a route list.
 */
class ClassSource
{
    protected ?Parser $parser = null;

    /**
     * Parsed statements, keyed by file. Names are resolved once per file, and
     * one controller's request and resource classes are usually asked about
     * several times over a single export.
     *
     * @var array<string, array<int, Node>|null>
     */
    protected array $files = [];

    /** The method's declaration, or null if anything in the chain fails. */
    public function method(string $class, string $method): ?ClassMethod
    {
        $declaration = $this->declaration($class);

        if ($declaration === null) {
            return null;
        }

        foreach ($declaration->getMethods() as $candidate) {
            if ($candidate->name->toString() === $method) {
                return $candidate;
            }
        }

        // Declared on a parent — worth following, since base controllers and
        // base resources are where shared shapes tend to live.
        $parent = $this->parentOf($class);

        return $parent === null ? null : $this->method($parent, $method);
    }

    /**
     * The array literal a method returns.
     *
     * The *last* return wins. A method that returns early on some condition is
     * describing a special case; the shape at the end is the general one, and
     * it is the one worth documenting.
     */
    public function returnedArray(ClassMethod $method): ?Array_
    {
        $found = null;

        foreach ($this->find($method, Return_::class) as $return) {
            /** @var Return_ $return */
            if ($return->expr instanceof Array_) {
                $found = $return->expr;
            }
        }

        return $found;
    }

    /**
     * The closure declared at a given file and line.
     *
     * Route files are full of closures, and a small application may put its
     * validation in one. Reflection gives the file and start line; that pair
     * addresses the node as precisely as a name addresses a method.
     */
    public function closure(string $file, int $line): ?Node\Expr\Closure
    {
        if (! is_file($file)) {
            return null;
        }

        $statements = $this->files[$file] ??= $this->parse($file);

        if ($statements === null) {
            return null;
        }

        foreach ((new NodeFinder)->findInstanceOf($statements, Node\Expr\Closure::class) as $closure) {
            /** @var Node\Expr\Closure $closure */
            if ($closure->getStartLine() === $line) {
                return $closure;
            }
        }

        return null;
    }

    /**
     * Nodes of one type anywhere inside another.
     *
     * @param  class-string  $type
     * @return array<int, Node>
     */
    public function find(Node $node, string $type): array
    {
        return (new NodeFinder)->findInstanceOf($node, $type);
    }

    /** String keys of an array literal, paired with the expression behind each. */
    public function keyed(Array_ $array): array
    {
        $pairs = [];

        foreach ($array->items as $item) {
            // A spread, or a key that is not a plain string, describes something
            // this cannot name — skipping it loses one row rather than the
            // whole shape.
            if ($item === null || $item->key === null) {
                continue;
            }

            $key = $item->key;

            if (! $key instanceof Node\Scalar\String_) {
                continue;
            }

            $pairs[$key->value] = $item->value;
        }

        return $pairs;
    }

    /**
     * A whole file's statements, parsed and cached like any other.
     *
     * The other entry points here start from a class and find its file. This
     * one starts from the file, because scanning a directory for call sites is
     * a different question from reading one class's method — and both want the
     * same parse, resolved the same way, cached once.
     *
     * @return array<int, Node>|null
     */
    public function file(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        if (array_key_exists($path, $this->files)) {
            return $this->files[$path];
        }

        return $this->files[$path] = $this->parse($path);
    }

    /** The class declaration inside its own file, with names already resolved. */
    protected function declaration(string $class): ?Class_
    {
        $statements = $this->statements($class);

        if ($statements === null) {
            return null;
        }

        $target = ltrim($class, '\\');

        foreach ((new NodeFinder)->findInstanceOf($statements, Class_::class) as $declaration) {
            /** @var Class_ $declaration */
            // A file can hold more than one class; namespacedName is what the
            // NameResolver pass leaves behind to tell them apart.
            $name = $declaration->namespacedName?->toString() ?? $declaration->name?->toString();

            if ($name === $target) {
                return $declaration;
            }
        }

        return null;
    }

    /** @return array<int, Node>|null */
    protected function statements(string $class): ?array
    {
        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return null;
        }

        if ($file === false || ! is_file($file)) {
            // An internal or eval'd class: nothing on disk to read.
            return null;
        }

        if (array_key_exists($file, $this->files)) {
            return $this->files[$file];
        }

        return $this->files[$file] = $this->parse($file);
    }

    /** @return array<int, Node>|null */
    protected function parse(string $file): ?array
    {
        try {
            $this->parser ??= (new ParserFactory)->createForHostVersion();

            $statements = $this->parser->parse((string) file_get_contents($file));

            if ($statements === null) {
                return null;
            }

            // Resolves imports and relative names, so a `new AuthorResource(…)`
            // written under a `use` statement arrives as its fully qualified
            // name — which is the only form worth comparing against.
            // ParentConnectingVisitor rides along so a consumer that has found a
            // node deep inside an expression can walk back up to what it belongs
            // to. That is how a dispatch site reads the `->onQueue(...)` chained
            // onto it. It costs one attribute per node and changes nothing for
            // the analyzers that never look at it.
            $traverser = new NodeTraverser(new NameResolver, new ParentConnectingVisitor);

            return $traverser->traverse($statements);
        } catch (Throwable) {
            // A syntax error, an unreadable file, or a PHP version this parser
            // does not know. None of those are the route list's problem.
            return null;
        }
    }

    protected function parentOf(string $class): ?string
    {
        try {
            $parent = (new ReflectionClass($class))->getParentClass();
        } catch (Throwable) {
            return null;
        }

        if ($parent === false) {
            return null;
        }

        // Stop at the framework: Illuminate's own base classes have no rules or
        // fields of their own, and walking into vendor code costs a parse per
        // level for nothing.
        return str_starts_with($parent->getName(), 'Illuminate\\') ? null : $parent->getName();
    }
}
