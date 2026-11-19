<?php

namespace Workbench\Domains\Attributes;

use Attribute;

/**
 * The application's own domain marker. dissect matches it by short name, so
 * nothing from the package has to be required in production for it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Domain
{
    public function __construct(public string $name) {}
}
