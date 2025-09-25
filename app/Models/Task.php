<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use App\Enums\TaskType;

/**
 * @property int $id
 * @property \App\Models\Query $submittedQuery
 * @property \App\Models\Collection $collection
 */
class Task extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $fillable = [
        'pid',
        'query_id',
        'collection_id',
        'created_at',
        'completed_at',
        'attempted_at',
        'failed_at',
        'attempts',
        'task_type'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
        'attempted_at' => 'datetime',
        'failed_at' => 'datetime',
        'task_type' => TaskType::class,
    ];

    protected static function booted(): void
    {
        static::creating(function ($task) {
            $task->pid = $task->pid ?? (string) Str::uuid();
        });
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_id', 'id');
    }

    public function submittedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id', 'id');
    }

    public function result(): HasOne
    {
        return $this->hasOne(Result::class);
    }

    public function resultFiles()
    {
        return $this->hasMany(ResultFile::class);
    }
}
