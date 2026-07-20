<?php

namespace KdrDev\Dissect\Types;

/**
 * Postgres reports the most verbose type names of any supported driver —
 * "timestamp(0) without time zone" for what everyone calls a timestamp — which
 * is the main reason this abstraction exists.
 */
class PostgresTypeNormalizer extends BaseTypeNormalizer
{
    protected function map(): array
    {
        return [
            // Strings
            'character varying' => ['varchar', ColumnKind::String, true],
            'varchar' => ['varchar', ColumnKind::String, true],
            'character' => ['char', ColumnKind::String, true],
            'char' => ['char', ColumnKind::String, true],
            'bpchar' => ['char', ColumnKind::String, true],
            'text' => ['text', ColumnKind::String, false],
            'citext' => ['citext', ColumnKind::String, false],
            'name' => ['name', ColumnKind::String, false],

            // Integers
            'smallint' => ['smallint', ColumnKind::Integer, false],
            'int2' => ['smallint', ColumnKind::Integer, false],
            'integer' => ['int', ColumnKind::Integer, false],
            'int' => ['int', ColumnKind::Integer, false],
            'int4' => ['int', ColumnKind::Integer, false],
            'bigint' => ['bigint', ColumnKind::Integer, false],
            'int8' => ['bigint', ColumnKind::Integer, false],
            'smallserial' => ['smallserial', ColumnKind::Integer, false],
            'serial' => ['serial', ColumnKind::Integer, false],
            'bigserial' => ['bigserial', ColumnKind::Integer, false],

            // Floating point / exact numeric
            'numeric' => ['numeric', ColumnKind::Float, true],
            'decimal' => ['decimal', ColumnKind::Float, true],
            'real' => ['real', ColumnKind::Float, false],
            'float4' => ['real', ColumnKind::Float, false],
            'double precision' => ['double', ColumnKind::Float, false],
            'float8' => ['double', ColumnKind::Float, false],
            'money' => ['money', ColumnKind::Float, false],

            'boolean' => ['bool', ColumnKind::Boolean, false],
            'bool' => ['bool', ColumnKind::Boolean, false],

            // Dates and times. Parameters are precision and rarely interesting.
            'timestamp without time zone' => ['timestamp', ColumnKind::DateTime, false],
            'timestamp with time zone' => ['timestamptz', ColumnKind::DateTime, false],
            'timestamp' => ['timestamp', ColumnKind::DateTime, false],
            'timestamptz' => ['timestamptz', ColumnKind::DateTime, false],
            'date' => ['date', ColumnKind::Date, false],
            'time without time zone' => ['time', ColumnKind::Time, false],
            'time with time zone' => ['timetz', ColumnKind::Time, false],
            'time' => ['time', ColumnKind::Time, false],
            'timetz' => ['timetz', ColumnKind::Time, false],
            'interval' => ['interval', ColumnKind::Time, false],

            'json' => ['json', ColumnKind::Json, false],
            'jsonb' => ['jsonb', ColumnKind::Json, false],

            'uuid' => ['uuid', ColumnKind::Uuid, false],
            'bytea' => ['bytea', ColumnKind::Binary, false],

            'inet' => ['inet', ColumnKind::Network, false],
            'cidr' => ['cidr', ColumnKind::Network, false],
            'macaddr' => ['macaddr', ColumnKind::Network, false],
            'macaddr8' => ['macaddr8', ColumnKind::Network, false],

            'point' => ['point', ColumnKind::Spatial, false],
            'polygon' => ['polygon', ColumnKind::Spatial, false],
            'geometry' => ['geometry', ColumnKind::Spatial, false],
            'geography' => ['geography', ColumnKind::Spatial, false],

            'tsvector' => ['tsvector', ColumnKind::String, false],
            'xml' => ['xml', ColumnKind::String, false],
        ];
    }

    protected function resolve(string $native, string $base, ?string $params): ?NormalizedType
    {
        // Postgres reports arrays as "integer[]" or, internally, "_int4".
        if (str_ends_with($base, '[]')) {
            $inner = $this->normalize(substr($native, 0, -2));

            return new NormalizedType($native, $inner->label.'[]', $inner->kind);
        }

        return null;
    }
}
