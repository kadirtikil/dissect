<?php

namespace KdrDev\Dissect\Routes;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use KdrDev\Dissect\Routes\Ast\ClassSource;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\Int_;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Works out what an endpoint returns.
 *
 * Nothing in the framework can be asked this: a response shape only exists once
 * a request has produced one. So it is read from source — the return type names
 * the class, and the class's `toArray()` names the keys.
 *
 * That makes `confidence` mean something specific here:
 *
 *  - `certain`   the resource said which model it describes (a `@mixin`), and
 *                its `toArray()` was a literal this could read.
 *  - `inferred`  something was guessed — usually that `PostResource` describes
 *                a `Post`, which is true almost always and not quite always.
 *  - `unknown`   the class was found but its shape could not be read at all.
 */
class ResponseAnalyzer
{
    /**
     * How far to follow nested resources.
     *
     * Two levels answers "what comes back and what is inside it" for almost
     * every payload, and stops a pair of resources that embed each other from
     * unrolling forever.
     */
    protected const MAX_DEPTH = 2;

    public function __construct(
        protected ClassSource $source,
        protected ModelLinker $models,
    ) {}

    /**
     * @return array<string, mixed>|null null when there is no action to read
     */
    public function analyse(?ReflectionFunctionAbstract $action): ?array
    {
        if ($action === null) {
            return null;
        }

        try {
            return $this->describe($action);
        } catch (Throwable) {
            // Reading somebody else's source is best-effort by nature. An
            // endpoint listed without its response beats a page that 500s.
            return $this->shape('unknown', null, 'unknown', []);
        }
    }

    /** @return array<string, mixed> */
    protected function describe(ReflectionFunctionAbstract $action): array
    {
        $returns = $this->returnClass($action);
        $body = $this->bodyOf($action);

        // A view or a redirect has no body to describe, and saying "no fields"
        // about one is more useful than leaving it looking unanalysed.
        if ($returns !== null && $this->isA($returns, 'Illuminate\Contracts\View\View')) {
            return $this->shape('view', $returns, 'certain', []);
        }

        if ($returns !== null && $this->isA($returns, 'Illuminate\Http\RedirectResponse')) {
            return $this->shape('redirect', $returns, 'certain', []);
        }

        // A resource named directly by the return type is the clearest case.
        $resource = $this->resourceFrom($returns, $body);

        if ($resource !== null) {
            $collection = $returns !== null && $this->isCollection($returns);

            return $this->fromResource($resource, $collection, $body);
        }

        if ($body !== null) {
            $json = $this->jsonLiteral($body);

            if ($json !== null) {
                return $this->shape('json', $returns, 'inferred', $this->literalFields($json), $this->status($body));
            }
        }

        return $this->shape('unknown', $returns, 'unknown', []);
    }

    /**
     * A resource class and the shape its `toArray()` describes.
     *
     * @return array<string, mixed>
     */
    protected function fromResource(string $resource, bool $collection, ?Node $body): array
    {
        $model = $this->modelFor($resource);

        $fields = $this->resourceFields($resource);

        return $this->shape(
            $collection ? 'resource-collection' : 'resource',
            $resource,
            // The naming convention is a guess, however reliable; a `@mixin` is
            // a statement. An unreadable toArray() is neither.
            $fields === [] ? 'unknown' : ($model['stated'] ? 'certain' : 'inferred'),
            $fields,
            $body === null ? null : $this->status($body),
        );
    }

    /**
     * The keys a resource's `toArray()` returns, nested resources included.
     *
     * @param  array<int, string>  $seen
     * @return array<int, array<string, mixed>>
     */
    protected function resourceFields(
        string $resource,
        string $prefix = '',
        int $depth = 0,
        array $seen = [],
    ): array {
        $method = $this->source->method($resource, 'toArray');
        $array = $method === null ? null : $this->source->returnedArray($method);

        if ($array === null) {
            return [];
        }

        $model = $this->modelFor($resource)['id'];
        $fields = [];

        foreach ($this->source->keyed($array) as $key => $value) {
            $path = $prefix === '' ? $key : $prefix.'.'.$key;
            $nested = $this->resourceIn($value);
            $conditional = $this->isConditional($value);

            if ($nested === null) {
                // `$this->title` is a column on the mixed-in model; anything
                // computed is a value with nothing behind it, and claiming a
                // column for it would be a lie. A column is only reported
                // alongside the model it belongs to — on its own it names
                // nothing anybody can follow.
                $attribute = $model !== null && $this->isAttribute($value);

                $fields[] = [
                    'path' => $path,
                    'kind' => 'scalar',
                    'model' => $attribute ? $model : null,
                    'column' => $attribute ? $this->attributeName($value) : null,
                    'conditional' => $conditional,
                ];

                continue;
            }

            [$nestedClass, $isCollection] = $nested;
            $nestedModel = $this->modelFor($nestedClass)['id'];
            $nestedPath = $isCollection ? $path.'[]' : $path;

            $fields[] = [
                'path' => $nestedPath,
                'kind' => $isCollection ? 'array' : 'object',
                'model' => $nestedModel,
                'column' => null,
                'conditional' => $conditional,
            ];

            // Guarded twice over: by depth, and by not re-entering a resource
            // already on this branch. Two resources embedding each other is a
            // real pattern, not a pathological one.
            if ($depth + 1 < self::MAX_DEPTH && ! in_array($nestedClass, $seen, true)) {
                $fields = [...$fields, ...$this->resourceFields(
                    $nestedClass,
                    $nestedPath,
                    $depth + 1,
                    [...$seen, $resource],
                )];
            }
        }

        return $fields;
    }

    /**
     * The model a resource describes, and whether it said so itself.
     *
     * @return array{id: string|null, stated: bool}
     */
    protected function modelFor(string $resource): array
    {
        try {
            $reflection = new ReflectionClass($resource);
        } catch (Throwable) {
            return ['id' => null, 'stated' => false];
        }

        // `@mixin \App\Models\Post` — how a resource tells an IDE (and now this)
        // which model its property access resolves against.
        $doc = $reflection->getDocComment();

        if ($doc !== false && preg_match('/@mixin\s+\\\\?([\w\\\\]+)/', $doc, $matches) === 1) {
            $id = $this->models->forClass($matches[1]);

            if ($id !== null) {
                return ['id' => $id, 'stated' => true];
            }
        }

        // The convention: PostResource describes a Post. Only accepted when the
        // graph actually holds a node by that name, so it stays a check rather
        // than a guess.
        $basename = $reflection->getShortName();
        $candidate = preg_replace('/(Resource|Collection)$/', '', $basename);

        if (is_string($candidate) && $candidate !== '' && $this->models->knows($candidate)) {
            return ['id' => $candidate, 'stated' => false];
        }

        return ['id' => null, 'stated' => false];
    }

    /**
     * The resource class this endpoint hands back, from the return type or,
     * failing that, from the body.
     */
    protected function resourceFrom(?string $returns, ?Node $body): ?string
    {
        if ($returns !== null && $this->isA($returns, JsonResource::class) && ! $this->isCollection($returns)) {
            return $returns;
        }

        if ($body === null) {
            return null;
        }

        // `AnonymousResourceCollection` names no resource of its own, and
        // neither does a `JsonResponse` built from one — but the body always
        // mentions the class that was collected or made.
        foreach ($this->source->find($body, StaticCall::class) as $call) {
            /** @var StaticCall $call */
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            if (! in_array($call->name->toString(), ['collection', 'make'], true)) {
                continue;
            }

            $class = $call->class instanceof Node\Name ? $call->class->toString() : null;

            if ($class !== null && $this->isA($class, JsonResource::class)) {
                return $class;
            }
        }

        foreach ($this->source->find($body, New_::class) as $new) {
            /** @var New_ $new */
            $class = $new->class instanceof Node\Name ? $new->class->toString() : null;

            if ($class !== null && $this->isA($class, JsonResource::class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * A nested resource inside one field's value, and whether it is a list.
     *
     * @return array{0: string, 1: bool}|null
     */
    protected function resourceIn(Expr $value): ?array
    {
        foreach ($this->source->find($value, StaticCall::class) as $call) {
            /** @var StaticCall $call */
            $class = $call->class instanceof Node\Name ? $call->class->toString() : null;

            if ($class === null || ! $this->isA($class, JsonResource::class)) {
                continue;
            }

            $method = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

            return [$class, $method === 'collection'];
        }

        foreach ($this->source->find($value, New_::class) as $new) {
            /** @var New_ $new */
            $class = $new->class instanceof Node\Name ? $new->class->toString() : null;

            if ($class !== null && $this->isA($class, JsonResource::class)) {
                return [$class, $this->isCollection($class)];
            }
        }

        return null;
    }

    /**
     * Wrapped in one of the "maybe" helpers, so the key is not always in the
     * payload — which a consumer reading this as a contract needs to know.
     */
    protected function isConditional(Expr $value): bool
    {
        foreach ($this->source->find($value, MethodCall::class) as $call) {
            /** @var MethodCall $call */
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $name = $call->name->toString();

            if (in_array($name, ['when', 'whenLoaded', 'whenNotNull', 'mergeWhen', 'whenHas'], true)) {
                return true;
            }
        }

        return false;
    }

    /** `$this->title` — a plain attribute read, which is a column. */
    protected function isAttribute(Expr $value): bool
    {
        return $value instanceof PropertyFetch
            && $value->var instanceof Expr\Variable
            && $value->var->name === 'this'
            && $value->name instanceof Node\Identifier;
    }

    protected function attributeName(Expr $value): ?string
    {
        return $this->isAttribute($value) && $value instanceof PropertyFetch
            && $value->name instanceof Node\Identifier
                ? $value->name->toString()
                : null;
    }

    /** The array literal handed to `response()->json([…])`. */
    protected function jsonLiteral(Node $body): ?Array_
    {
        foreach ($this->source->find($body, MethodCall::class) as $call) {
            /** @var MethodCall $call */
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'json') {
                continue;
            }

            if (($call->args[0] ?? null) instanceof Node\Arg && $call->args[0]->value instanceof Array_) {
                return $call->args[0]->value;
            }
        }

        return null;
    }

    /**
     * An explicit status code, from either spelling.
     *
     * Only a literal counts. A computed status is a status this cannot state,
     * and guessing 200 would be wrong precisely where it matters.
     */
    protected function status(Node $body): ?int
    {
        foreach ($this->source->find($body, MethodCall::class) as $call) {
            /** @var MethodCall $call */
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $name = $call->name->toString();

            $argument = match ($name) {
                'setStatusCode' => $call->args[0] ?? null,
                'json' => $call->args[1] ?? null,
                default => null,
            };

            if ($argument instanceof Node\Arg && $argument->value instanceof Int_) {
                return $argument->value->value;
            }
        }

        return null;
    }

    /**
     * Keys of a plain array literal — no model behind them, since a hand-built
     * payload is not a resource.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function literalFields(Array_ $array): array
    {
        $fields = [];

        foreach ($this->source->keyed($array) as $key => $value) {
            $fields[] = [
                'path' => $key,
                'kind' => $value instanceof Array_ ? 'array' : 'scalar',
                'model' => null,
                'column' => null,
                'conditional' => false,
            ];
        }

        return $fields;
    }

    protected function returnClass(ReflectionFunctionAbstract $action): ?string
    {
        $type = $action->getReturnType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return $type->getName();
    }

    protected function bodyOf(ReflectionFunctionAbstract $action): ?Node
    {
        if ($action instanceof ReflectionMethod) {
            return $this->source->method($action->class, $action->getName());
        }

        if ($action instanceof ReflectionFunction && $action->getFileName()) {
            return $this->source->closure($action->getFileName(), $action->getStartLine());
        }

        return null;
    }

    protected function isCollection(string $class): bool
    {
        return $this->isA($class, ResourceCollection::class)
            || $class === 'Illuminate\Http\Resources\Json\AnonymousResourceCollection';
    }

    /** class_exists() first: is_a() with a string would autoload it otherwise. */
    protected function isA(string $class, string $parent): bool
    {
        return class_exists($class) || interface_exists($class)
            ? is_a($class, $parent, true)
            : false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    protected function shape(
        string $source,
        ?string $class,
        string $confidence,
        array $fields,
        ?int $status = null,
    ): array {
        return [
            'source' => $source,
            'class' => $class,
            'status' => $status,
            'confidence' => $confidence,
            'fields' => $fields,
        ];
    }
}
