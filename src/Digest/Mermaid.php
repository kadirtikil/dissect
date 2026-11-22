<?php

namespace KdrDev\Dissect\Digest;

use KdrDev\Dissect\Support\ClassNames;

/**
 * The models as a Mermaid ER diagram, for a README, a wiki or a pull request.
 *
 * GitHub, GitLab and most docs sites draw these without a plugin, so the
 * graph can live next to the code it describes. It is the graph without the
 * layout: Mermaid places the boxes itself.
 *
 * A relation and its inverse are one line in an ER diagram, so a belongs-to
 * whose keys match a has-one or has-many the other way is drawn once, named
 * by both ends, and so are two many-to-manys through the same pivot table.
 * Everything else is drawn as declared.
 */
final class Mermaid
{
    /** How many on each end, as Mermaid writes it: left end, line, right end. */
    protected const CARDINALITY = [
        'HasOne' => '||--o|',
        'MorphOne' => '||--o|',
        'HasMany' => '||--o{',
        'MorphMany' => '||--o{',
        'BelongsTo' => '}o--||',
        'BelongsToMany' => '}o--o{',
        'MorphToMany' => '}o--o{',
        'MorphedByMany' => '}o--o{',
        // Dotted: the link runs through a model in between.
        'HasOneThrough' => '||..o|',
        'HasManyThrough' => '||..o{',
    ];

    /** @param  array<string, mixed>  $schema */
    public function schema(array $schema): string
    {
        $nodes = $schema['nodes'] ?? [];
        $edges = $schema['edges'] ?? [];

        $ids = array_column($nodes, 'id');

        foreach ($edges as $edge) {
            $ids[] = $edge['source'];
            $ids[] = $edge['target'];
        }

        $names = array_map($this->entity(...), ClassNames::short($ids));
        $lines = ['erDiagram'];

        foreach ($nodes as $node) {
            $lines = [...$lines, ...$this->model($node, $names[$node['id']], $this->foreignKeys($node['id'], $edges))];
        }

        foreach ($this->relations($edges) as [$edge, $label]) {
            $cardinality = self::CARDINALITY[$edge['type']] ?? null;

            if ($cardinality === null) {
                continue;
            }

            $lines[] = '    '.$names[$edge['source']].' '.$cardinality.' '.$names[$edge['target']].' : "'.$label.'"';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, true>  $foreign
     * @return array<int, string>
     */
    protected function model(array $node, string $name, array $foreign): array
    {
        $columns = array_filter($node['columns'] ?? [], 'is_array');

        if ($columns === []) {
            return ['    '.$name.' {', '    }'];
        }

        $lines = ['    '.$name.' {'];

        foreach ($columns as $column) {
            $keys = array_keys(array_filter([
                'PK' => $column['increments'] ?? false,
                'FK' => isset($foreign[$column['name']]),
                'UK' => ($column['unique'] ?? false) && ! ($column['increments'] ?? false),
            ]));

            $line = '        '.$this->type($column).' '.$this->identifier($column['name']);

            if ($keys !== []) {
                $line .= ' '.implode(', ', $keys);
            }

            if ($column['nullable'] ?? false) {
                $line .= ' "nullable"';
            }

            $lines[] = $line;
        }

        $lines[] = '    }';

        return $lines;
    }

    /**
     * Which edges to draw, each with its label, inverses folded together.
     *
     * @param  array<int, array<string, mixed>>  $edges
     * @return array<int, array{0: array<string, mixed>, 1: string}>
     */
    protected function relations(array $edges): array
    {
        $folded = [];
        $drawn = [];

        foreach ($edges as $i => $edge) {
            if (isset($folded[$i])) {
                continue;
            }

            foreach ($edges as $j => $other) {
                if ($i === $j || isset($folded[$j])) {
                    continue;
                }

                if ($edge['type'] === 'BelongsTo' && $this->inverse($edge, $other)) {
                    // Drawn from the owning side, the way the has-many reads.
                    $folded[$i] = $folded[$j] = true;
                    $drawn[$j] = $other['name'].' / '.$edge['name'];

                    break;
                }

                if ($this->samePivot($edge, $other)) {
                    $folded[$i] = $folded[$j] = true;
                    $drawn[$i] = $edge['name'].' / '.$other['name'];

                    break;
                }
            }
        }

        $relations = [];

        foreach ($edges as $i => $edge) {
            if (isset($drawn[$i])) {
                $relations[] = [$edge, $drawn[$i]];
            } elseif (! isset($folded[$i])) {
                $relations[] = [$edge, $edge['name']];
            }
        }

        return $relations;
    }

    /**
     * A has-one or has-many the other way along the same pair of columns.
     *
     * @param  array<string, mixed>  $belongsTo
     * @param  array<string, mixed>  $other
     */
    protected function inverse(array $belongsTo, array $other): bool
    {
        $mine = $belongsTo['keys'] ?? null;
        $theirs = $other['keys'] ?? null;

        return in_array($other['type'], ['HasOne', 'HasMany'], true)
            && $other['source'] === $belongsTo['target']
            && $other['target'] === $belongsTo['source']
            && $mine !== null && $theirs !== null
            && $mine['source'] !== null && $mine['target'] !== null
            && $theirs['source'] === $mine['target']
            && $theirs['target'] === $mine['source'];
    }

    /**
     * Two many-to-manys that are the same table seen from either end.
     *
     * @param  array<string, mixed>  $edge
     * @param  array<string, mixed>  $other
     */
    protected function samePivot(array $edge, array $other): bool
    {
        $many = ['BelongsToMany', 'MorphToMany', 'MorphedByMany'];
        $via = $edge['keys']['via'] ?? null;

        return in_array($edge['type'], $many, true)
            && in_array($other['type'], $many, true)
            && $edge['source'] !== $edge['target']
            && $other['source'] === $edge['target']
            && $other['target'] === $edge['source']
            && $via !== null
            && ($other['keys']['via'] ?? null) === $via;
    }

    /**
     * Columns a model's own belongs-to relations point from.
     *
     * @param  array<int, array<string, mixed>>  $edges
     * @return array<string, true>
     */
    protected function foreignKeys(string $id, array $edges): array
    {
        $keys = [];

        foreach ($edges as $edge) {
            if ($edge['source'] === $id && in_array($edge['type'], ['BelongsTo', 'MorphTo'], true) && ($edge['keys']['source'] ?? null) !== null) {
                $keys[$edge['keys']['source']] = true;
            }
        }

        return $keys;
    }

    /**
     * The column type as Mermaid accepts one: a single word, so `varchar(255)`
     * is `varchar` and `numeric(6,5)` is `numeric`.
     *
     * @param  array<string, mixed>  $column
     */
    protected function type(array $column): string
    {
        $type = (string) ($column['label'] ?? $column['type'] ?? $column['kind'] ?? '');

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', $type, $match) ? $match[0] : 'unknown';
    }

    /** A short class name as an entity name: `Billing\Invoice` is `Billing_Invoice`. */
    protected function entity(string $name): string
    {
        return $this->identifier(str_replace('\\', '_', $name));
    }

    protected function identifier(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $name);

        return preg_match('/^[A-Za-z_]/', $name) ? $name : '_'.$name;
    }
}
