<?php

namespace Workbench\Domains\Http\Controllers\Auth;

/**
 * Put in Identity by domains.json, not by its folder.
 */
class LoginController
{
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
