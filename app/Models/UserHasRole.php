<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserHasRole extends Model
{
    public $table = 'user_has_roles';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'role_id',
    ];
}
