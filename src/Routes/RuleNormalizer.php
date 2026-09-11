<?php

namespace KdrDev\Dissect\Routes;

use Stringable;
use Throwable;

/**
 * Turns one validation entry into one described field.
 *
 * Validation rules arrive in every shape Laravel accepts — a piped string, an
 * array of strings, a Rule object, a closure — and the viewer needs one answer:
 * what is this field called, what type is it, is it required, and does it point
 * at something in the graph.
 *
 * Deliberately free of Illuminate imports, like `src/Types/`: it is a pure
 * function over strings and objects, so it can be exercised without booting a
 * framework or touching a database.
 */
class RuleNormalizer
{
    /**
     * Rules that say what a value *is*, as opposed to whether it is allowed.
     * Order matters only in that the first match is what gets shown.
     */
    protected const TYPES = [
        'integer', 'numeric', 'decimal', 'string', 'boolean', 'array', 'date',
        'file', 'image', 'email', 'url', 'uuid', 'ulid', 'json', 'timezone', 'ip',
    ];

    /** Rules whose first argument names a table or a model class. */
    protected const REFERENCES = ['exists', 'unique'];

    /**
     * @return array{path: string, type: string|null, required: bool, rules: array<int, string>, table: string|null, class: string|null, column: string|null}
     */
    public function field(string $path, mixed $rules): array
    {
        $list = $this->flatten($rules);
        $reference = $this->reference($list);

        return [
            'path' => $this->path($path),
            'type' => $this->type($list),
            'required' => in_array('required', $list, true),
            'rules' => $list,
            'table' => $reference['table'],
            'class' => $reference['class'],
            'column' => $reference['column'],
        ];
    }

    /**
     * `tags.*.name` becomes `tags[].name`.
     *
     * Both halves of the payload contract use one grammar for paths — a
     * resource's nested collection is written `lines[]` too — so request and
     * response fields can share a renderer instead of each having their own.
     */
    public function path(string $path): string
    {
        return str_replace(['.*.', '.*'], ['[].', '[]'], $path);
    }

    /**
     * Every rule as a string, however it was written.
     *
     * @return array<int, string>
     */
    protected function flatten(mixed $rules): array
    {
        if (is_string($rules)) {
            // The piped form. Empty segments come from a trailing or doubled
            // separator and describe nothing.
            $rules = explode('|', $rules);
        }

        if (! is_array($rules)) {
            $rules = [$rules];
        }

        $flat = [];

        foreach ($rules as $rule) {
            $string = $this->stringify($rule);

            if ($string !== null && $string !== '') {
                $flat[] = $string;
            }
        }

        return array_values(array_unique($flat));
    }

    protected function stringify(mixed $rule): ?string
    {
        if (is_string($rule)) {
            return trim($rule);
        }

        if (is_object($rule)) {
            // Most of Laravel's Rule objects render themselves back into the
            // string form, which is the form worth showing.
            if ($rule instanceof Stringable || method_exists($rule, '__toString')) {
                try {
                    return trim((string) $rule);
                } catch (Throwable) {
                    // A Rule that needs a request or a connection to render.
                    // Its class name still says what kind of rule it is.
                }
            }

            if ($rule instanceof \Closure) {
                return 'closure';
            }

            return $this->basename($rule::class);
        }

        return null;
    }

    /** The first rule that names a type, without its arguments. */
    protected function type(array $rules): ?string
    {
        foreach ($rules as $rule) {
            $name = strtolower(explode(':', $rule, 2)[0]);

            // `int` and `bool` are not rules Laravel knows, but people write
            // them, and reporting nothing would be less true than reporting
            // what they meant.
            $name = match ($name) {
                'int' => 'integer',
                'bool' => 'boolean',
                default => $name,
            };

            if (in_array($name, self::TYPES, true)) {
                return $name;
            }

            if (str_starts_with($name, 'date_format')) {
                return 'date';
            }
        }

        return null;
    }

    /**
     * @param $rules
     * The table, model class or column an `exists`/`unique` rule points at.
     *
     * Parsed but no longer reported: the routes export carries rules verbatim,
     * so `exists:authors,id` reaches the client as written rather than split
     * into a table and a column. Kept because reading a rule is this class's
     * job, and the JSON:API work will want the reference a field points at.
     *
     * @return array{table: string|null, class: string|null, column: string|null}
     */
    protected function reference(array $rules): array
    {
        foreach ($rules as $rule) {
            [$name, $arguments] = array_pad(explode(':', $rule, 2), 2, '');

            if (! in_array(strtolower($name), self::REFERENCES, true) || $arguments === '') {
                continue;
            }

            $parts = explode(',', $arguments);
            $target = trim($parts[0]);
            $column = isset($parts[1]) ? trim($parts[1]) : null;

            // Laravel accepts a model class in place of a table name, and
            // plenty of codebases prefer it.
            if (str_contains($target, '\\')) {
                return ['table' => null, 'class' => ltrim($target, '\\'), 'column' => $column ?: null];
            }

            // `exists:mysql.users,id` — the connection is not part of the name.
            if (str_contains($target, '.')) {
                $target = substr($target, strrpos($target, '.') + 1);
            }

            return ['table' => $target ?: null, 'class' => null, 'column' => $column ?: null];
        }

        return ['table' => null, 'class' => null, 'column' => null];
    }

    protected function basename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
