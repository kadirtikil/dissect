<?php

namespace Workbench\App\Enums;

/** Backs the `Rule::enum()` case in StorePostRequest. */
enum PostVisibility: string
{
    case Public = 'public';
    case Unlisted = 'unlisted';
    case Private = 'private';
}
