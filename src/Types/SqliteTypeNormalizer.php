<?php

namespace KdrDev\Dissect\Types;

/**
 * SQLite has dynamic typing: a column's declared type is a hint, and anything
 * can be declared. Laravel migrations produce a small, predictable set, but a
 * hand-written table can declare "VARCHAR(255)" or nothing at all — so the
 * fallback matters more here than on other drivers.
 */
class SqliteTypeNormalizer extends BaseTypeNormalizer
{
    protected function map(): array
    {
        return [
            'text' => ['text', ColumnKind::String, false],
            'varchar' => ['varchar', ColumnKind::String, true],
            'char' => ['char', ColumnKind::String, true],
            'clob' => ['clob', ColumnKind::String, false],

            'integer' => ['integer', ColumnKind::Integer, false],
            'int' => ['integer', ColumnKind::Integer, false],
            'tinyint' => ['tinyint', ColumnKind::Integer, false],
            'smallint' => ['smallint', ColumnKind::Integer, false],
            'mediumint' => ['mediumint', ColumnKind::Integer, false],
            'bigint' => ['bigint', ColumnKind::Integer, false],

            'real' => ['real', ColumnKind::Float, false],
            'double' => ['double', ColumnKind::Float, false],
            'float' => ['float', ColumnKind::Float, false],
            'numeric' => ['numeric', ColumnKind::Float, true],
            'decimal' => ['decimal', ColumnKind::Float, true],

            'boolean' => ['bool', ColumnKind::Boolean, false],

            'datetime' => ['datetime', ColumnKind::DateTime, false],
            'timestamp' => ['timestamp', ColumnKind::DateTime, false],
            'date' => ['date', ColumnKind::Date, false],
            'time' => ['time', ColumnKind::Time, false],

            'json' => ['json', ColumnKind::Json, false],
            'blob' => ['blob', ColumnKind::Binary, false],
        ];
    }

    protected function resolve(string $native, string $base, ?string $params): ?NormalizedType
    {
        // A column declared with no type at all is legal in SQLite.
        if ($base === '') {
            return new NormalizedType($native, 'any', ColumnKind::Unknown);
        }

        // Laravel's boolean columns land as tinyint(1) here just as they do on
        // MySQL, so the same declared type must produce the same kind on both.
        if ($base === 'tinyint' && $params === '1') {
            return new NormalizedType($native, 'bool', ColumnKind::Boolean);
        }

        return null;
    }
}
