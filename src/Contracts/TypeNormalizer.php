<?php

namespace KdrDev\Dissect\Contracts;

use KdrDev\Dissect\Types\NormalizedType;

/**
 * Turns a database's own type string into something portable.
 *
 * Bind your own implementation to support a driver this package does not ship,
 * or to override how an existing one is displayed:
 *
 *     TypeNormalizerManager::extend('firebird', fn () => new FirebirdTypes);
 */
interface TypeNormalizer
{
    /**
     * @param  string  $native  Exactly what the driver reported, e.g.
     *                          "character varying(255)" or "tinyint(1) unsigned".
     */
    public function normalize(string $native): NormalizedType;
}
