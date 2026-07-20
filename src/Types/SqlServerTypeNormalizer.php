<?php

namespace KdrDev\Dissect\Types;

class SqlServerTypeNormalizer extends BaseTypeNormalizer
{
    protected function map(): array
    {
        return [
            'varchar' => ['varchar', ColumnKind::String, true],
            'nvarchar' => ['nvarchar', ColumnKind::String, true],
            'char' => ['char', ColumnKind::String, true],
            'nchar' => ['nchar', ColumnKind::String, true],
            'text' => ['text', ColumnKind::String, false],
            'ntext' => ['ntext', ColumnKind::String, false],
            'xml' => ['xml', ColumnKind::String, false],

            'tinyint' => ['tinyint', ColumnKind::Integer, false],
            'smallint' => ['smallint', ColumnKind::Integer, false],
            'int' => ['int', ColumnKind::Integer, false],
            'bigint' => ['bigint', ColumnKind::Integer, false],

            'decimal' => ['decimal', ColumnKind::Float, true],
            'numeric' => ['numeric', ColumnKind::Float, true],
            'float' => ['float', ColumnKind::Float, false],
            'real' => ['real', ColumnKind::Float, false],
            'money' => ['money', ColumnKind::Float, false],
            'smallmoney' => ['smallmoney', ColumnKind::Float, false],

            // SQL Server has no boolean; bit is the convention.
            'bit' => ['bool', ColumnKind::Boolean, false],

            'datetime' => ['datetime', ColumnKind::DateTime, false],
            'datetime2' => ['datetime2', ColumnKind::DateTime, false],
            'smalldatetime' => ['smalldatetime', ColumnKind::DateTime, false],
            'datetimeoffset' => ['datetimeoffset', ColumnKind::DateTime, false],
            'date' => ['date', ColumnKind::Date, false],
            'time' => ['time', ColumnKind::Time, false],

            'uniqueidentifier' => ['uuid', ColumnKind::Uuid, false],

            'binary' => ['binary', ColumnKind::Binary, true],
            'varbinary' => ['varbinary', ColumnKind::Binary, true],
            'image' => ['image', ColumnKind::Binary, false],

            'geometry' => ['geometry', ColumnKind::Spatial, false],
            'geography' => ['geography', ColumnKind::Spatial, false],
        ];
    }

    protected function resolve(string $native, string $base, ?string $params): ?NormalizedType
    {
        // SQL Server reports unbounded columns as varchar(-1); "max" is how it
        // is written in SQL and how developers refer to it.
        if ($params === '-1' || $params === 'max') {
            $inner = $this->lookup($native, $base, null);

            if ($inner !== null) {
                return new NormalizedType($native, $inner->label.'(max)', $inner->kind);
            }
        }

        return null;
    }
}
