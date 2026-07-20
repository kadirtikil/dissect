<?php

namespace KdrDev\Dissect;

use KdrDev\Dissect\Types\ColumnKind;
use KdrDev\Dissect\Types\TypeNormalizerManager;

/**
 * Flattens ModelInspector's attribute rows into the shape the viewer consumes.
 *
 * Extracted from SchemaExporter so that the payload contract lives in one small
 * class: if the exported column shape changes, this is the only file to read.
 */
class ColumnNormalizer
{
    public function __construct(protected TypeNormalizerManager $types) {}

    /**
     * @param  iterable<int, array<string, mixed>>  $attributes
     * @param  string|null  $connection  Connection the model uses, so the right
     *                                   driver's normalizer is applied.
     * @return array<int, array<string, mixed>>
     */
    public function normalize(iterable $attributes, ?string $connection = null): array
    {
        $normalizer = $this->types->for($connection);
        $columns = [];

        foreach ($attributes as $attribute) {
            $native = $attribute['type'] ?? null;

            // Accessors and appended attributes have no column type: they are
            // model-level concepts rather than real table columns.
            $virtual = $native === null;

            $type = $virtual
                ? null
                : $normalizer->normalize((string) $native);

            $columns[] = [
                'name' => $attribute['name'],
                // Kept verbatim: when chasing a migration issue you want to see
                // exactly what the database reported.
                'type' => $native,
                'label' => $type?->label ?? 'accessor',
                'kind' => ($type?->kind ?? ColumnKind::Unknown)->value,
                'nullable' => (bool) ($attribute['nullable'] ?? false),
                'unique' => (bool) ($attribute['unique'] ?? false),
                'increments' => (bool) ($attribute['increments'] ?? false),
                'fillable' => (bool) ($attribute['fillable'] ?? false),
                'hidden' => (bool) ($attribute['hidden'] ?? false),
                'cast' => $attribute['cast'] ?? null,
                'virtual' => $virtual,
            ];
        }

        return $columns;
    }
}
