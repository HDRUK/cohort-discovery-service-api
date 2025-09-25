<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class UserHasWorkgroup extends Pivot
{
    //
    protected $table = 'user_has_workgroups';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'workgroup_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }
}
