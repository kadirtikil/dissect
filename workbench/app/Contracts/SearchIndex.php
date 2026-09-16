<?php

namespace Workbench\App\Contracts;

/**
 * The abstract half of a plain `bind()`.
 *
 * An interface is the common case a provider binds: the tree has to show the
 * contract somebody type-hints and the concrete it actually resolves to as two
 * nodes, not one.
 */
interface SearchIndex
{
    public function index(string $document): void;
}
