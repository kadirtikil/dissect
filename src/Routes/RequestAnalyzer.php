<?php

namespace KdrDev\Dissect\Routes;

use Illuminate\Foundation\Http\FormRequest;
use KdrDev\Dissect\Routes\Ast\ClassSource;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\PrettyPrinter\Standard;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Works out what an endpoint accepts.
 *
 * Two sources, tried in order, because applications use both: a form request
 * type-hinted on the action, or a `validate()` call inside it. Whichever
 * answers first is the one reported, along with how it was obtained — a shape
 * read from source is not as trustworthy as one the framework itself produced,
 * and saying so is the difference between documentation and guesswork.
 */
class RequestAnalyzer
{
    public function __construct(
        protected ClassSource $source,
        protected RuleNormalizer $rules,
    ) {}

    /**
     * @return array<string, mixed>|null null when there is no action to read at all
     */
    public function analyse(?ReflectionFunctionAbstract $action): ?array
    {
        if ($action === null) {
            return null;
        }

        return $this->fromFormRequest($action)
            ?? $this->fromInlineValidation($action)
            ?? $this->nothing($action);
    }

    /**
     * The type-hinted form request, if there is one.
     *
     * @return array<string, mixed>|null
     */
    protected function fromFormRequest(ReflectionFunctionAbstract $action): ?array
    {
        $class = $this->formRequestClass($action);

        if ($class === null) {
            return null;
        }

        // Running rules() is the only way to see rules that are built rather
        // than written — a match on the HTTP verb, a merge of a shared set.
        // Constructed bare rather than through the container: resolving a form
        // request would bind it to whatever request the viewer itself is
        // serving, which is not the one being described.
        try {
            $rules = $this->quietly(static fn () => (new $class)->rules());

            if (is_array($rules)) {
                return $this->shape('form-request', $class, 'certain', $rules);
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
            return $this->shape('form-request', $class, 'unknown', []);
        }

        return $this->shape('form-request', $class, 'inferred', $this->literalRules($array));
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
    protected function quietly(callable $callback): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * A `validate()` call in the action's own body.
     *
     * @return array<string, mixed>|null
     */
    protected function fromInlineValidation(ReflectionFunctionAbstract $action): ?array
    {
        $node = $this->nodeFor($action);

        if ($node === null) {
            return null;
        }

        $array = $this->validationArgument($node);

        if ($array === null) {
            return null;
        }

        // Always inferred: this was read off the page, never evaluated.
        return $this->shape('inline-validate', null, 'inferred', $this->literalRules($array));
    }

    /**
     * Nothing found — but "there is no validation here" and "this could not be
     * read" are different answers, and the confidence is what separates them.
     *
     * @return array<string, mixed>
     */
    protected function nothing(ReflectionFunctionAbstract $action): array
    {
        return [
            'source' => 'none',
            'class' => null,
            'confidence' => $this->nodeFor($action) === null ? 'unknown' : 'certain',
            'fields' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function shape(string $source, ?string $class, string $confidence, array $rules): array
    {
        $fields = [];

        foreach ($rules as $path => $rule) {
            if (! is_string($path)) {
                continue;
            }

            $field = $this->rules->field($path, $rule);

            // Rules travel verbatim. `exists:authors,id` names a table, and
            // reading it is exactly as informative as resolving it to a node
            // would be — without making the endpoint's contract depend on the
            // model graph having found that model.
            $fields[] = [
                'path' => $field['path'],
                'type' => $field['type'],
                'required' => $field['required'],
                'rules' => $field['rules'],
            ];
        }

        return [
            'source' => $source,
            'class' => $class,
            'confidence' => $confidence,
            'fields' => $fields,
        ];
    }

    /** @return class-string<FormRequest>|null */
    protected function formRequestClass(ReflectionFunctionAbstract $action): ?string
    {
        foreach ($action->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (class_exists($class) && is_subclass_of($class, FormRequest::class)) {
                return $class;
            }
        }

        return null;
    }

    /** The action's body as syntax, whether it is a method or a closure. */
    protected function nodeFor(ReflectionFunctionAbstract $action): ?Node
    {
        if ($action instanceof ReflectionMethod) {
            return $this->source->method($action->class, $action->getName());
        }

        if ($action instanceof ReflectionFunction && $action->getFileName()) {
            return $this->source->closure($action->getFileName(), $action->getStartLine());
        }

        return null;
    }

    /**
     * The rules array handed to whichever validation call the body makes.
     *
     * Three spellings, all common enough to be worth recognising:
     * `$request->validate([…])`, `$this->validate($request, […])` and
     * `Validator::make($request->all(), […])`.
     */
    protected function validationArgument(Node $node): ?Array_
    {
        foreach ($this->source->find($node, MethodCall::class) as $call) {
            /** @var MethodCall $call */
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'validate') {
                continue;
            }

            // $request->validate([…]) puts the rules first; the controller
            // helper $this->validate($request, […]) puts the request there.
            foreach (array_slice($call->args, 0, 2) as $argument) {
                if ($argument instanceof Node\Arg && $argument->value instanceof Array_) {
                    return $argument->value;
                }
            }
        }

        foreach ($this->source->find($node, StaticCall::class) as $call) {
            /** @var StaticCall $call */
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'make') {
                continue;
            }

            if (($call->args[1] ?? null) instanceof Node\Arg && $call->args[1]->value instanceof Array_) {
                return $call->args[1]->value;
            }
        }

        return null;
    }

    /**
     * An array literal of rules, read back into the form the normalizer takes.
     *
     * @return array<string, mixed>
     */
    protected function literalRules(Array_ $array): array
    {
        $rules = [];

        foreach ($this->source->keyed($array) as $path => $value) {
            if ($value instanceof Array_) {
                $rules[$path] = array_values(array_filter(
                    array_map($this->ruleText(...), array_column($this->itemsOf($value), 'value')),
                ));

                continue;
            }

            $rules[$path] = $this->ruleText($value);
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
    protected function ruleText(Expr $expr): ?string
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
