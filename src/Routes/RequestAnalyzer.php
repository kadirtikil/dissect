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
     * A call whose rules are not written out where it is made, such as
     * `$request->validate($this->rules())`, still says the endpoint validates
     * something. It is reported as that, unread, rather than falling through
     * to "validates nothing", which would be the one answer known to be false.
     *
     * @return array<string, mixed>|null
     */
    protected function fromInlineValidation(ReflectionFunctionAbstract $action): ?array
    {
        $node = $this->nodeFor($action);

        if ($node === null) {
            return null;
        }

        $found = $this->validationArgument($node);

        if ($found === false) {
            return null;
        }

        if ($found === null) {
            return $this->shape('inline-validate', null, 'unknown', []);
        }

        // Always inferred: this was read off the page, never evaluated.
        return $this->shape('inline-validate', null, 'inferred', $this->reader->literal($found));
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

            if ($this->isFormRequest($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Whether a parameter's type is a FormRequest.
     *
     * Asking autoloads the class, which throws for a file that does not parse.
     * One broken type hint is no reason to stop looking at the parameters after
     * it, so it answers "no" and the loop moves on.
     */
    protected function isFormRequest(string $class): bool
    {
        try {
            return class_exists($class) && is_subclass_of($class, FormRequest::class);
        } catch (Throwable) {
            return false;
        }
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
     * The rules array handed to whichever validation call the body makes:
     * the array when it is written there, null when there is a call but its
     * rules are built elsewhere, false when there is no call at all.
     *
     * The spellings recognised, all common enough to be worth it:
     * `$request->validate([…])`, `$request->validateWithBag('bag', […])`,
     * the controller helper `$this->validate($request, […])`,
     * `Validator::make($data, […])` and `validator($data, […])`.
     */
    protected function validationArgument(Node $node): Array_|null|false
    {
        $found = false;

        foreach ($this->source->find($node, MethodCall::class) as $call) {
            /** @var MethodCall $call */
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : null;

            // On a request the rules come first; the controller helper takes
            // the request first, and a bag's name comes before its rules.
            $position = match (true) {
                $name === 'validateWithBag' => 1,
                $name !== 'validate' => null,
                $call->var instanceof Node\Expr\Variable && $call->var->name === 'this' => 1,
                default => 0,
            };

            if ($position === null) {
                continue;
            }

            $array = $this->arrayAt($call->args, $position);

            if ($array !== null) {
                return $array;
            }

            $found = null;
        }

        foreach ($this->source->find($node, StaticCall::class) as $call) {
            /** @var StaticCall $call */
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'make') {
                continue;
            }

            // `PostResource::make($post)` is a make() too.
            if (! $call->class instanceof Node\Name || $call->class->getLast() !== 'Validator') {
                continue;
            }

            $array = $this->arrayAt($call->args, 1);

            if ($array !== null) {
                return $array;
            }

            $found = null;
        }

        foreach ($this->source->find($node, Node\Expr\FuncCall::class) as $call) {
            /** @var Node\Expr\FuncCall $call */
            if (! $call->name instanceof Node\Name || $call->name->toLowerString() !== 'validator' || $call->args === []) {
                continue;
            }

            $array = $this->arrayAt($call->args, 1);

            if ($array !== null) {
                return $array;
            }

            $found = null;
        }

        return $found;
    }

    /** @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $arguments */
    protected function arrayAt(array $arguments, int $position): ?Array_
    {
        $argument = $arguments[$position] ?? null;

        return $argument instanceof Node\Arg && $argument->value instanceof Array_ ? $argument->value : null;
    }
}
