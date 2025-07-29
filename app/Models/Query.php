<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property array $definition
 */
class Query extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $fillable = [
        'pid',
        'name',
        'definition',
        'created_at',
    ];

    protected $casts = [
        'definition' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($query) {
            $query->pid = $query->pid ?? (string) Str::uuid();
        });
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
