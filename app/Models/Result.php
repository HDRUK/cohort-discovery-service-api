<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Result extends Model
{
    protected $fillable = [
        'task_id',
        'count',
        'metadata',
    ];

    protected $casts = [
        'count' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($res) {
            $res->pid = $res->pid ?? (string) Str::uuid();
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
