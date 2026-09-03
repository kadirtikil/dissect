<?php

namespace KdrDev\Dissect\Routes;

use Illuminate\Foundation\Http\FormRequest;
use KdrDev\Dissect\Routes\Ast\ClassSource;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
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
        protected RuleSource $reader,
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

        $read = $this->reader->read($class);

        return $this->shape('form-request', $class, $read['confidence'], $read['rules']);
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
        return $this->shape('inline-validate', null, 'inferred', $this->reader->literal($array));
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
}
