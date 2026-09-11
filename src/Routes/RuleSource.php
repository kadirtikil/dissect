<?php

namespace KdrDev\Dissect\Routes;

use ErrorException;
use KdrDev\Dissect\Routes\Ast\ClassSource;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\PrettyPrinter\Standard;
use Throwable;

/**
 * Reads a class's `rules()`, however it was written.
 *
 * Running it is tried first, because it is the only way to see rules that are
 * *built* rather than written — a match on the HTTP verb, a merge of a shared
 * set, a loop over a config array. Where that cannot be done the method's body
 * is read as syntax instead, and where neither works the class is reported as
 * unreadable rather than as accepting nothing.
 *
 * Those three outcomes are the three confidence grades, and keeping them apart
 * is the point: "this endpoint validates nothing" and "this could not be read"
 * look identical in an empty field list and mean opposite things.
 *
 * Shared by {@see RequestAnalyzer}, which finds the class on a controller
 * action, and by {@see \KdrDev\Dissect\JsonApi\DocumentAnalyzer}, which finds it
 * beside a JSON:API schema. Both need the same fallback ladder and neither
 * should own it.
 */
class RuleSource
{
    public function __construct(protected ClassSource $source) {}

    /**
     * @return array{rules: array<string, mixed>, confidence: string}
     */
    public function read(string $class): array
    {
        // Constructed bare rather than through the container: resolving a form
        // request would bind it to whatever request the viewer itself is
        // serving, which is not the one being described.
        try {
            $rules = $this->quietly(static fn () => (new $class)->rules());

            if (is_array($rules)) {
                return ['rules' => $rules, 'confidence' => 'certain'];
            }
        } catch (Throwable) {
            // Overwhelmingly the common failure: rules() reaching for
            // $this->route() to build a unique-ignoring rule. Falling back is
            // the whole reason the parser is here.
        }

        $method = $this->source->method($class, 'rules');
        $array = $method === null ? null : $this->source->returnedArray($method);

        if ($array === null) {
            // The class exists and declares rules the viewer cannot read.
            // Saying so beats reporting an endpoint that accepts nothing.
            return ['rules' => [], 'confidence' => 'unknown'];
        }

        return ['rules' => $this->literal($array), 'confidence' => 'inferred'];
    }

    /**
     * Runs a callback with diagnostics promoted to exceptions.
     *
     * `rules()` written against a live request typically does not throw when
     * called without one — it reads a property on null and carries on with a
     * warning. Two things then go wrong: the rule it built is nonsense, and the
     * warning lands in the host application's log, blamed on a page that was
     * only looking. Promoting it means the same code path that catches a real
     * exception falls back to reading the source instead.
     */
    public function quietly(callable $callback): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * An array literal of rules, read back into the form the normalizer takes.
     *
     * @return array<string, mixed>
     */
    public function literal(Array_ $array): array
    {
        $rules = [];

        foreach ($this->source->keyed($array) as $path => $value) {
            if ($value instanceof Array_) {
                $rules[$path] = array_values(array_filter(
                    array_map($this->text(...), array_column($this->itemsOf($value), 'value')),
                ));

                continue;
            }

            $rules[$path] = $this->text($value);
        }

        return $rules;
    }

    /** @return array<int, array{value: Expr}> */
    protected function itemsOf(Array_ $array): array
    {
        $items = [];

        foreach ($array->items as $item) {
            if ($item !== null) {
                $items[] = ['value' => $item->value];
            }
        }

        return $items;
    }

    /**
     * One rule expression as the string form Laravel also accepts.
     *
     * `Rule::exists('authors', 'id')` is rewritten to `exists:authors,id` so
     * that a rule written in the fluent style and one written as a string land
     * on the same description.
     */
    public function text(Expr $expr): ?string
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }

        // `Rule::unique('posts')->ignore($post->id)` — the chained part refines
        // the rule, but the rule itself is the head of the chain, and it is the
        // half worth reporting.
        while ($expr instanceof MethodCall) {
            $expr = $expr->var;
        }

        if ($expr instanceof StaticCall && $expr->name instanceof Node\Identifier) {
            $arguments = [];

            foreach ($expr->args as $argument) {
                $text = $argument instanceof Node\Arg ? $this->argumentText($argument->value) : null;

                if ($text !== null && $text !== '') {
                    $arguments[] = $text;
                }
            }

            $name = $expr->name->toString();

            return $arguments === [] ? $name : $name.':'.implode(',', $arguments);
        }

        try {
            // Anything else is shown as it was written. Less useful than a
            // parsed rule, but truer than dropping it.
            return (new Standard)->prettyPrintExpr($expr);
        } catch (Throwable) {
            return null;
        }
    }

    protected function argumentText(Expr $expr): ?string
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }

        // `PostVisibility::class`, already resolved to its full name by the
        // NameResolver pass the parser runs.
        if ($expr instanceof ClassConstFetch && $expr->class instanceof Node\Name) {
            return $expr->class->toString();
        }

        if ($expr instanceof Array_) {
            return implode(',', array_filter(
                array_map($this->argumentText(...), array_column($this->itemsOf($expr), 'value')),
            ));
        }

        return null;
    }
}
