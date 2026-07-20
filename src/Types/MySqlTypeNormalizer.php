<?php

namespace KdrDev\Dissect\Types;

/**
 * MySQL and MariaDB. Type names are already short, so most entries pass through
 * unchanged; the work here is `tinyint(1)` and the unsigned modifier.
 */
class MySqlTypeNormalizer extends BaseTypeNormalizer
{
    protected function map(): array
    {
        return [
            'varchar' => ['varchar', ColumnKind::String, true],
            'char' => ['char', ColumnKind::String, true],
            'tinytext' => ['tinytext', ColumnKind::String, false],
            'text' => ['text', ColumnKind::String, false],
            'mediumtext' => ['mediumtext', ColumnKind::String, false],
            'longtext' => ['longtext', ColumnKind::String, false],

            'tinyint' => ['tinyint', ColumnKind::Integer, false],
            'smallint' => ['smallint', ColumnKind::Integer, false],
            'mediumint' => ['mediumint', ColumnKind::Integer, false],
            'int' => ['int', ColumnKind::Integer, false],
            'integer' => ['int', ColumnKind::Integer, false],
            'bigint' => ['bigint', ColumnKind::Integer, false],
            'bit' => ['bit', ColumnKind::Integer, false],

            'decimal' => ['decimal', ColumnKind::Float, true],
            'numeric' => ['numeric', ColumnKind::Float, true],
            'float' => ['float', ColumnKind::Float, false],
            'double' => ['double', ColumnKind::Float, false],

            'boolean' => ['bool', ColumnKind::Boolean, false],
            'bool' => ['bool', ColumnKind::Boolean, false],

            'datetime' => ['datetime', ColumnKind::DateTime, false],
            'timestamp' => ['timestamp', ColumnKind::DateTime, false],
            'date' => ['date', ColumnKind::Date, false],
            'time' => ['time', ColumnKind::Time, false],
            'year' => ['year', ColumnKind::Date, false],

            'json' => ['json', ColumnKind::Json, false],

            'binary' => ['binary', ColumnKind::Binary, true],
            'varbinary' => ['varbinary', ColumnKind::Binary, true],
            'tinyblob' => ['tinyblob', ColumnKind::Binary, false],
            'blob' => ['blob', ColumnKind::Binary, false],
            'mediumblob' => ['mediumblob', ColumnKind::Binary, false],
            'longblob' => ['longblob', ColumnKind::Binary, false],

            'enum' => ['enum', ColumnKind::Enum, false],
            'set' => ['set', ColumnKind::Enum, false],

            'geometry' => ['geometry', ColumnKind::Spatial, false],
            'point' => ['point', ColumnKind::Spatial, false],
            'polygon' => ['polygon', ColumnKind::Spatial, false],
            'linestring' => ['linestring', ColumnKind::Spatial, false],
        ];
    }

    protected function resolve(string $native, string $base, ?string $params): ?NormalizedType
    {
        // "int unsigned" and friends: strip the modifier for the lookup but keep
        // it visible in the label, since signedness matters when reading a schema.
        $unsigned = str_contains($base, 'unsigned');

        if ($unsigned) {
            $stripped = trim(str_replace(['unsigned', 'zerofill'], '', $base));
            $inner = $this->lookup($native, $this->normaliseWhitespace($stripped), $params);

            if ($inner !== null) {
                return new NormalizedType($native, $inner->label.' unsigned', $inner->kind);
            }
        }

        // Laravel and the wider ecosystem treat tinyint(1) as a boolean.
        if ($base === 'tinyint' && $params === '1') {
            return new NormalizedType($native, 'bool', ColumnKind::Boolean);
        }

        return null;
    }
}
