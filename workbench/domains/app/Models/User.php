<?php

namespace Workbench\Domains\Models;

use Illuminate\Database\Eloquent\Model;
use Workbench\Domains\Attributes\Domain;

/**
 * Shared folder, owned elsewhere: the case the attribute is for.
 */
#[Domain('Identity')]
class User extends Model
{
    protected $table = 'domain_users';
}
