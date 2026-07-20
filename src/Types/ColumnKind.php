<?php

namespace KdrDev\Dissect\Types;

/**
 * Driver-independent meaning of a column.
 *
 * The viewer keys off this rather than the native type string, so `varchar(255)`
 * on MySQL, `character varying(255)` on Postgres and `TEXT` on SQLite all render
 * the same way. It is also the natural hook for future icons or colour-coding.
 */
enum ColumnKind: string
{
    case String = 'string';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case DateTime = 'datetime';
    case Date = 'date';
    case Time = 'time';
    case Json = 'json';
    case Uuid = 'uuid';
    case Binary = 'binary';
    case Enum = 'enum';
    case Network = 'network';
    case Spatial = 'spatial';

    /** A type the driver's map does not cover; rendered plainly rather than guessed at. */
    case Unknown = 'unknown';
}
