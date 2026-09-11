<?php

namespace KdrDev\Dissect\JsonApi;

use Illuminate\Routing\Route;
use LaravelJsonApi\Contracts\Schema\ID;
use LaravelJsonApi\Contracts\Schema\Relation;
use LaravelJsonApi\Contracts\Schema\Schema;
use KdrDev\Dissect\Routes\RuleNormalizer;
use KdrDev\Dissect\Routes\RuleSource;
use Throwable;

/**
 * Describes what a JSON:API endpoint sends and returns.
 *
 * A JSON:API response is not the resource's fields at the top level — it is an
 * envelope, and the envelope is most of what a consumer has to write code
 * against. `data.attributes.title` is the path that matters; `title` on its own
 * would be a lie about where the value is.
 *
 * Which envelope depends on the action, and the action is the controller method
 * the package routed to:
 *
 * | Action                                  | Shape                            |
 * |-----------------------------------------|----------------------------------|
 * | `index`                                 | a collection of resource objects |
 * | `store`, `show`, `update`               | one resource object              |
 * | `destroy`                               | no content                       |
 * | `showRelated`                           | the *related* schema's resources |
 * | `show/update/attach/detachRelationship` | resource identifiers only        |
 *
 * A relationship endpoint deliberately reports `type` and `id` and nothing
 * else. That is the whole of a resource identifier object, and saying otherwise
 * would promise attributes that never arrive.
 */
class DocumentAnalyzer
{
    /** Actions whose response is a list rather than a single resource. */
    protected const COLLECTION = ['index'];

    /** Actions answering with resource identifiers rather than resources. */
    protected const IDENTIFIERS = [
        'showRelationship',
        'updateRelationship',
        'attachRelationship',
        'detachRelationship',
    ];

    /** Actions that send a document rather than only receiving one. */
    protected const WRITES = ['store', 'update'];

    public function __construct(
        protected ServerRegistry $registry,
        protected RuleSource $reader,
        protected RuleNormalizer $rules,
    ) {}

    /**
     * The response half, or null when this is not a JSON:API route.
     *
     * @return array<string, mixed>|null
     */
    public function response(Route $route): ?array
    {
        $target = $this->target($route);

        if ($target === null) {
            return null;
        }

        [$schema, $action] = $target;

        if ($action === 'destroy') {
            // 204, and a body would be a protocol violation rather than an
            // omission — so this is an answer, not a failure to look.
            return $this->shape('json-api-none', $schema, [], 204);
        }

        $collection = in_array($action, self::COLLECTION, true)
            || ($action === 'showRelated' && $this->isToMany($route));

        $fields = in_array($action, self::IDENTIFIERS, true)
            ? $this->identifiers($schema, $this->isToMany($route))
            : $this->resource($schema, $collection);

        return $this->shape(
            in_array($action, self::IDENTIFIERS, true) ? 'json-api-identifier' : 'json-api',
            $schema,
            $fields,
            $action === 'store' ? 201 : 200,
        );
    }

    /**
     * The request half, for the actions that carry a document.
     *
     * The document's shape comes from the schema — those are the fields a
     * client may send — and its constraints from the resource's
     * `ResourceRequest`, where it has one. Two sources because they answer two
     * questions: the schema says what `title` *is*, the request says whether it
     * is required and what it has to look like.
     *
     * Rules are keyed by field name, not by document path: a `ResourceRequest`
     * writes `'title' => ['required']`, and where that value actually sits is
     * `data.attributes.title`. Mapping the one onto the other is the whole of
     * what this has to work out, and the schema is what says which names are
     * relationships rather than attributes.
     *
     * @return array<string, mixed>|null
     */
    public function request(Route $route): ?array
    {
        $target = $this->target($route);

        if ($target === null) {
            return null;
        }

        [$schema, $action] = $target;

        if (! in_array($action, self::WRITES, true)) {
            return null;
        }

        $fields = [
            ['path' => 'data', 'type' => 'object', 'required' => true, 'rules' => []],
            ['path' => 'data.type', 'type' => 'string', 'required' => true, 'rules' => []],
        ];

        // `id` is the client's on a create only when the schema accepts one;
        // on an update it addresses the resource and is always required.
        if ($action === 'update') {
            $fields[] = ['path' => 'data.id', 'type' => 'string', 'required' => true, 'rules' => []];
        }

        $attributes = [];

        foreach ($this->fields($schema) as $field) {
            if ($field instanceof ID || $field instanceof Relation) {
                continue;
            }

            $attributes[] = [
                'path' => 'data.attributes.'.$field->name(),
                'type' => null,
                // A JSON:API update is a patch: sending a field is optional by
                // construction, whatever the validation rules say about it.
                'required' => false,
                'rules' => [],
            ];
        }

        if ($attributes !== []) {
            $fields[] = ['path' => 'data.attributes', 'type' => 'object', 'required' => false, 'rules' => []];
            $fields = [...$fields, ...$attributes];
        }

        $relations = $this->relations($schema);

        if ($relations !== []) {
            $fields[] = ['path' => 'data.relationships', 'type' => 'object', 'required' => false, 'rules' => []];
        }

        foreach ($relations as $relation) {
            $fields[] = [
                'path' => 'data.relationships.'.$relation->name(),
                'type' => 'object',
                'required' => false,
                'rules' => [],
            ];
            $fields[] = [
                'path' => 'data.relationships.'.$relation->name().'.data',
                'type' => $relation->toMany() ? 'array' : 'object',
                'required' => false,
                'rules' => [],
            ];
        }

        $validation = $this->validation($schema);

        return [
            'source' => 'json-api',
            // The request class where there is one: it is the file somebody
            // opens to change what this endpoint accepts, and the schema is
            // already named on the response half.
            'class' => $validation['class'] ?? $schema::class,
            'confidence' => $validation['confidence'],
            'fields' => $this->constrain($fields, $validation['rules'], $action === 'update'),
        ];
    }

    /**
     * A resource's validation rules, already mapped onto document paths.
     *
     * @return array{class: string|null, confidence: string, rules: array<string, array<string, mixed>>}
     */
    protected function validation(Schema $schema): array
    {
        $class = $this->registry->requestClass($schema);

        if ($class === null) {
            // No `ResourceRequest`, which is an answer rather than a gap. The
            // document's shape is still exactly the schema's, and that is what
            // this half describes — so `certain`. What is absent is the
            // constraints, and absent constraints are honestly reported as no
            // rules rather than as a shape nobody could read.
            return ['class' => null, 'confidence' => 'certain', 'rules' => []];
        }

        $read = $this->reader->read($class);
        $relations = [];

        foreach ($this->relations($schema) as $relation) {
            $relations[] = $relation->name();
        }

        $mapped = [];

        foreach ($read['rules'] as $key => $rule) {
            if (! is_string($key)) {
                continue;
            }

            $field = $this->rules->field($key, $rule);
            $mapped[$this->documentPath($field['path'], $relations)] = $field;
        }

        return ['class' => $class, 'confidence' => $read['confidence'], 'rules' => $mapped];
    }

    /**
     * Where a validated field name sits in the document.
     *
     * Only the first segment is looked up — `author.data` validates the linkage
     * inside a relationship, and the half that decides which container it lands
     * in is `author`.
     *
     * @param  array<int, string>  $relations
     */
    protected function documentPath(string $path, array $relations): string
    {
        [$head, $rest] = array_pad(explode('.', $path, 2), 2, null);

        $container = in_array($head, $relations, true) ? 'relationships' : 'attributes';

        return 'data.'.$container.'.'.$head.($rest === null ? '' : '.'.$rest);
    }

    /**
     * Folds the rules onto the fields the schema produced.
     *
     * The schema decides which fields exist, so a rule for something it does not
     * declare is dropped rather than added: a `ResourceRequest` often validates
     * keys that never reach the wire, and inventing a document field for one
     * would describe a payload the API does not accept.
     *
     * On an update `required` is deliberately not taken from the rules. A
     * JSON:API update is a patch — every field may be omitted, and the rules
     * describe what a value has to look like *if it is sent*. Copying
     * `required` across would tell a client it must resend the whole resource
     * to change one attribute, which is the opposite of what the endpoint does.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<int, array<string, mixed>>
     */
    protected function constrain(array $fields, array $rules, bool $patch): array
    {
        foreach ($fields as $index => $field) {
            $rule = $rules[$field['path']] ?? null;

            if ($rule === null) {
                continue;
            }

            $fields[$index]['type'] = $rule['type'] ?? $field['type'];
            $fields[$index]['rules'] = $rule['rules'];

            if (! $patch) {
                $fields[$index]['required'] = $rule['required'];
            }
        }

        return $fields;
    }

    /**
     * The schema and action a route addresses.
     *
     * @return array{0: Schema, 1: string}|null
     */
    protected function target(Route $route): ?array
    {
        $address = $this->registry->address($route);

        if ($address === null) {
            return null;
        }

        $schema = $this->registry->schema($address['server'], $address['resource']);

        if ($schema === null) {
            return null;
        }

        $action = $route->getActionMethod();

        // `showRelated` answers with the *other* end of the relation, so the
        // schema that describes the response is the inverse one.
        //
        // Where that schema cannot be found — a relation pointing at a resource
        // the server does not register — this reports nothing rather than
        // falling back to the schema the route was reached *from*. Describing
        // `posts/{post}/comments` with a post's own fields would be confidently
        // wrong, which is the one thing the exporter must never be.
        if ($action === 'showRelated' && $address['relation'] !== null) {
            $schema = $this->inverse($schema, $address['relation'], $address['server']);

            if ($schema === null) {
                return null;
            }
        }

        return [$schema, $action];
    }

    /** The schema on the far side of a relation. */
    protected function inverse(Schema $schema, string $name, string $server): ?Schema
    {
        try {
            $relation = $this->relationNamed($schema, $name);

            return $relation === null
                ? null
                : $this->registry->schema($server, $relation->inverse());
        } catch (Throwable) {
            return null;
        }
    }

    protected function isToMany(Route $route): bool
    {
        $address = $this->registry->address($route);

        if ($address === null || $address['relation'] === null) {
            return false;
        }

        $schema = $this->registry->schema($address['server'], $address['resource']);
        $relation = $schema === null ? null : $this->relationNamed($schema, $address['relation']);

        return $relation?->toMany() ?? false;
    }

    protected function relationNamed(Schema $schema, string $name): ?Relation
    {
        foreach ($this->relations($schema) as $relation) {
            // The URI spells the relation, which is not always its field name.
            if ($relation->uriName() === $name || $relation->name() === $name) {
                return $relation;
            }
        }

        return null;
    }

    /**
     * One resource object, or a list of them.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function resource(Schema $schema, bool $collection): array
    {
        $root = $collection ? 'data[]' : 'data';

        $fields = [
            ['path' => $root, 'kind' => $collection ? 'array' : 'object', 'conditional' => false],
            ['path' => $root.'.type', 'kind' => 'scalar', 'conditional' => false],
            ['path' => $root.'.id', 'kind' => 'scalar', 'conditional' => false],
        ];

        $attributes = [];

        foreach ($this->fields($schema) as $field) {
            if ($field instanceof ID || $field instanceof Relation) {
                continue;
            }

            $attributes[] = [
                'path' => $root.'.attributes.'.$field->name(),
                'kind' => 'scalar',
                // Sparse fieldsets: a client asking for `fields[posts]=title`
                // gets only that, so no attribute is promised unconditionally.
                'conditional' => $field->isSparseField(),
            ];
        }

        // The containers are emitted as fields of their own, not just implied
        // by the paths beneath them. They are real keys in the document, and
        // the field tree only nests a path under a parent it can see — without
        // these every attribute would render as its own full dotted path.
        if ($attributes !== []) {
            $fields[] = ['path' => $root.'.attributes', 'kind' => 'object', 'conditional' => false];
            $fields = [...$fields, ...$attributes];
        }

        $relations = $this->relations($schema);

        if ($relations !== []) {
            $fields[] = [
                'path' => $root.'.relationships',
                'kind' => 'object',
                'conditional' => false,
            ];
        }

        foreach ($relations as $relation) {
            $fields[] = [
                'path' => $root.'.relationships.'.$relation->name(),
                'kind' => 'object',
                'conditional' => false,
            ];
            $fields[] = [
                'path' => $root.'.relationships.'.$relation->name().'.data',
                'kind' => $relation->toMany() ? 'array' : 'object',
                // Only present when the client asks for it, or the server
                // chooses to include it — a link is always there, data is not.
                'conditional' => true,
            ];
        }

        return $fields;
    }

    /**
     * A resource identifier document: type and id, and nothing else.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function identifiers(Schema $schema, bool $toMany): array
    {
        $root = $toMany ? 'data[]' : 'data';

        return [
            ['path' => $root, 'kind' => $toMany ? 'array' : 'object', 'conditional' => false],
            ['path' => $root.'.type', 'kind' => 'scalar', 'conditional' => false],
            ['path' => $root.'.id', 'kind' => 'scalar', 'conditional' => false],
        ];
    }

    /** @return iterable<mixed> */
    protected function fields(Schema $schema): iterable
    {
        try {
            return $schema->fields();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, Relation> */
    protected function relations(Schema $schema): array
    {
        $relations = [];

        foreach ($this->fields($schema) as $field) {
            if ($field instanceof Relation) {
                $relations[] = $field;
            }
        }

        return $relations;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    protected function shape(string $source, Schema $schema, array $fields, int $status): array
    {
        return [
            'source' => $source,
            'class' => $schema::class,
            'status' => $status,
            // Read from the package's own registry rather than inferred from
            // source, which is as certain as this exporter ever gets.
            'confidence' => 'certain',
            'fields' => $fields,
        ];
    }
}
