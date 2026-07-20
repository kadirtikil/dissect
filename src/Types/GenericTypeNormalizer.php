<?php

namespace KdrDev\Dissect\Types;

/**
 * Fallback for drivers this package does not know.
 *
 * It reports the native type unchanged with kind `unknown`, so an unsupported
 * database degrades to "no semantic grouping" rather than to a wrong answer or
 * an exception. Recognising a few near-universal names costs nothing and covers
 * most of what an exotic driver reports.
 */
class GenericTypeNormalizer extends BaseTypeNormalizer
{
    protected function map(): array
    {
        return [
            'varchar' => ['varchar', ColumnKind::String, true],
            'char' => ['char', ColumnKind::String, true],
            'text' => ['text', ColumnKind::String, false],
            'int' => ['int', ColumnKind::Integer, false],
            'integer' => ['int', ColumnKind::Integer, false],
            'bigint' => ['bigint', ColumnKind::Integer, false],
            'smallint' => ['smallint', ColumnKind::Integer, false],
            'decimal' => ['decimal', ColumnKind::Float, true],
            'numeric' => ['numeric', ColumnKind::Float, true],
            'float' => ['float', ColumnKind::Float, false],
            'double' => ['double', ColumnKind::Float, false],
            'boolean' => ['bool', ColumnKind::Boolean, false],
            'bool' => ['bool', ColumnKind::Boolean, false],
            'date' => ['date', ColumnKind::Date, false],
            'time' => ['time', ColumnKind::Time, false],
            'timestamp' => ['timestamp', ColumnKind::DateTime, false],
            'datetime' => ['datetime', ColumnKind::DateTime, false],
            'json' => ['json', ColumnKind::Json, false],
            'uuid' => ['uuid', ColumnKind::Uuid, false],
            'blob' => ['blob', ColumnKind::Binary, false],
        ];
    }
}
