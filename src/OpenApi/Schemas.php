<?php

namespace KdrDev\Dissect\OpenApi;

/**
 * Request and response fields, as JSON Schema.
 *
 * Both halves of the routes payload list fields by path, in one grammar:
 * `author.name` for a nested object, `tags[].name` for each item of a list.
 * The paths are folded back into a tree here, and each leaf gets the
 * keywords its rules or its kind justify, and no more: a response scalar
 * whose type dissect cannot see says nothing about its type, rather than
 * guessing one an API client would then trust.
 */
final class Schemas
{
    /** The rule that names a type, as the JSON Schema type it stands for. */
    protected const TYPES = [
        'integer' => 'integer',
        'numeric' => 'number',
        'decimal' => 'number',
        'boolean' => 'boolean',
        'string' => 'string',
        'date' => 'string',
        'email' => 'string',
        'url' => 'string',
        'uuid' => 'string',
        'ulid' => 'string',
        'json' => 'string',
        'timezone' => 'string',
        'ip' => 'string',
        'file' => 'string',
        'image' => 'string',
        'object' => 'object',
    ];

    /** Formats JSON Schema has a name for, and only those. */
    protected const FORMATS = [
        'email' => 'email',
        'url' => 'uri',
        'uuid' => 'uuid',
        'file' => 'binary',
        'image' => 'binary',
    ];

    /**
     * A request's fields, read off its validation rules.
     *
     * @param  array<int, array{path: string, type?: string|null, required?: bool, rules?: array<int, string>}>  $fields
     * @return array<string, mixed>
     */
    public static function request(array $fields): array
    {
        $tree = self::tree(array_map(fn (array $field) => [
            'path' => $field['path'],
            'schema' => self::fromRules($field['type'] ?? null, $field['rules'] ?? []),
            'required' => (bool) ($field['required'] ?? false),
        ], $fields));

        return self::render($tree);
    }

    /**
     * A response's fields, read off what the resource or array returns.
     *
     * Required unless conditional: a field behind `when()` or `whenLoaded()`
     * is the one a client must not count on.
     *
     * @param  array<int, array{path: string, kind?: string, conditional?: bool}>  $fields
     * @return array<string, mixed>
     */
    public static function response(array $fields): array
    {
        $tree = self::tree(array_map(fn (array $field) => [
            'path' => $field['path'],
            'schema' => self::fromKind($field['kind'] ?? 'scalar', (bool) ($field['conditional'] ?? false)),
            'required' => ! ($field['conditional'] ?? false),
        ], $fields));

        return self::render($tree);
    }

    /** Whether any of these request fields is a file, which makes the body a form. */
    public static function hasFile(array $fields): bool
    {
        foreach ($fields as $field) {
            if (in_array($field['type'] ?? null, ['file', 'image'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What one field's rules say about its value.
     *
     * The rules themselves go in the description, verbatim, as code: a Laravel
     * developer reads `required|integer|exists:authors,id` faster than any
     * translation of it, and `exists` has no JSON Schema keyword anyway.
     *
     * @param  array<int, string>  $rules
     * @return array<string, mixed>
     */
    public static function fromRules(?string $type, array $rules): array
    {
        $schema = [];
        $json = $type === null ? null : (self::TYPES[$type] ?? null);

        if ($type === 'array') {
            // `array` alone accepts a list or a map, and nothing in the rules
            // says which. Its children, if any, settle it later.
            $schema['type'] = ['array', 'object'];
        } elseif ($json !== null) {
            $schema['type'] = $json;
        }

        if ($type !== null && isset(self::FORMATS[$type])) {
            $schema['format'] = self::FORMATS[$type];
        }

        // What min/max bound: a file's are kilobytes, which nothing here can say.
        $bounded = in_array($type, ['file', 'image'], true) ? null : ($json ?? $type);

        foreach ($rules as $rule) {
            [$name, $arguments] = array_pad(explode(':', $rule, 2), 2, null);
            $name = strtolower($name);

            switch ($name) {
                case 'min':
                    $schema += self::bounds($bounded, $arguments, null);
                    break;
                case 'max':
                    $schema += self::bounds($bounded, null, $arguments);
                    break;
                case 'size':
                    $schema += self::bounds($bounded, $arguments, $arguments);
                    break;
                case 'between':
                    [$low, $high] = array_pad(explode(',', (string) $arguments, 2), 2, null);
                    $schema += self::bounds($bounded, $low, $high);
                    break;
                case 'in':
                    $schema['enum'] = self::values((string) $arguments, $json);
                    break;
                case 'regex':
                    $pattern = self::pattern((string) $arguments);

                    if ($pattern !== null) {
                        $schema['pattern'] = $pattern;
                    }

                    break;
                case 'date_format':
                    if ($arguments === 'Y-m-d') {
                        $schema['format'] = 'date';
                    }

                    break;
            }
        }

        // `Rule::enum()` and `Rule::in()` name no type rule, but a list of
        // strings is a string.
        if (! isset($schema['type']) && isset($schema['enum']) && $schema['enum'] !== [] && array_filter($schema['enum'], 'is_string') === $schema['enum']) {
            $schema['type'] = 'string';
        }

        if (in_array('nullable', $rules, true)) {
            $schema = self::nullable($schema);
        }

        // As code: OpenAPI descriptions are Markdown.
        if ($rules !== []) {
            $schema['description'] = '`'.implode('|', $rules).'`';
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    public static function fromKind(string $kind, bool $conditional = false): array
    {
        $schema = match ($kind) {
            'object' => ['type' => 'object'],
            'array' => ['type' => 'array'],
            default => [],
        };

        if ($conditional) {
            $schema['description'] = 'Only sometimes present: behind when(), whenLoaded() or the like.';
        }

        return $schema;
    }

    /**
     * Adds null to a schema's type. One with no type already allows null.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function nullable(array $schema): array
    {
        if (! isset($schema['type'])) {
            return $schema;
        }

        $types = (array) $schema['type'];

        if (! in_array('null', $types, true)) {
            $types[] = 'null';
        }

        $schema['type'] = $types;

        if (isset($schema['enum'])) {
            $schema['enum'][] = null;
        }

        return $schema;
    }

    /**
     * What `min`, `max`, `size` and `between` bound depends on the type, as in
     * Laravel: a string's length, a number's value, a list's count. A file's
     * kilobytes have no keyword.
     *
     * @return array<string, int|float>
     */
    protected static function bounds(?string $type, ?string $low, ?string $high): array
    {
        [$min, $max] = match ($type) {
            'string' => ['minLength', 'maxLength'],
            'integer', 'number', 'numeric', 'decimal' => ['minimum', 'maximum'],
            'array' => ['minItems', 'maxItems'],
            default => [null, null],
        };

        if ($min === null) {
            return [];
        }

        $bounds = [];
        $numeric = $min === 'minimum';

        foreach ([$min => $low, $max => $high] as $keyword => $value) {
            if ($value === null || ! is_numeric(trim($value))) {
                continue;
            }

            // Lengths and counts are whole numbers; a numeric bound may not be.
            $bounds[$keyword] = $numeric ? trim($value) + 0 : (int) trim($value);
        }

        return $bounds;
    }

    /**
     * `in:"public","unlisted"` or `in:1,2,3`, as an enum. Numbers stay
     * numbers when the field is numeric.
     *
     * @return array<int, string|int|float>
     */
    protected static function values(string $arguments, ?string $type): array
    {
        $values = [];

        foreach (str_getcsv($arguments, ',', '"', '') as $value) {
            $value = (string) $value;

            $values[] = in_array($type, ['integer', 'number'], true) && is_numeric($value)
                ? $value + 0
                : $value;
        }

        return $values;
    }

    /**
     * A PHP regex as a JSON Schema pattern, when it can be one: `/.../` with
     * no flags. Anything else is left in the description, not mistranslated.
     */
    protected static function pattern(string $regex): ?string
    {
        if (preg_match('#^/(.*)/$#s', $regex, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Folds fields back into a tree by path.
     *
     * @param  array<int, array{path: string, schema: array<string, mixed>, required: bool}>  $fields
     * @return array<string, mixed>
     */
    protected static function tree(array $fields): array
    {
        $root = self::node();

        foreach ($fields as $field) {
            $node = &$root;

            foreach (explode('.', $field['path']) as $segment) {
                $name = rtrim($segment, '[]');
                $lists = intdiv(strlen($segment) - strlen($name), 2);

                if ($name !== '') {
                    $node['properties'][$name] ??= self::node();
                    $node = &$node['properties'][$name];
                }

                for ($i = 0; $i < $lists; $i++) {
                    $node['items'] ??= self::node();
                    $node = &$node['items'];
                }
            }

            // The field describes the last thing named in its path: for
            // `tags[]` that is the list's items, for `tags` the list itself.
            $node['schema'] = $field['schema'];
            $node['required'] = $field['required'];

            unset($node);
        }

        return $root;
    }

    /** @return array<string, mixed> */
    protected static function node(): array
    {
        return ['schema' => [], 'required' => false, 'properties' => [], 'items' => null];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    protected static function render(array $node): array
    {
        $schema = $node['schema'];
        $nullable = in_array('null', (array) ($schema['type'] ?? []), true);

        if ($node['properties'] !== []) {
            $schema['type'] = $nullable ? ['object', 'null'] : 'object';
            $schema['properties'] = [];
            $required = [];

            foreach ($node['properties'] as $name => $child) {
                $schema['properties'][$name] = self::render($child);

                if ($child['required']) {
                    $required[] = (string) $name;
                }
            }

            if ($required !== []) {
                $schema['required'] = $required;
            }
        } elseif ($node['items'] !== null) {
            $schema['type'] = $nullable ? ['array', 'null'] : 'array';
            $schema['items'] = self::render($node['items']);
        }

        return self::ordered($schema);
    }

    /**
     * Keywords in the order a reader of the document expects them, so the
     * same fields always give the same bytes.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected static function ordered(array $schema): array
    {
        $order = ['type', 'format', 'enum', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum', 'minItems', 'maxItems', 'description', 'properties', 'required', 'items'];
        $ordered = [];

        foreach ($order as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                $ordered[$keyword] = $schema[$keyword];
            }
        }

        return $ordered + $schema;
    }
}
