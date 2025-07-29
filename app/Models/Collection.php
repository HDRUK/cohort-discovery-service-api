<?php

namespace App\Models;

use App\Services\QueryContext\QueryContextType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $pid
 * @property QueryContextType $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Task[] $tasks
 */
class Collection extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'pid',
        'type',
    ];

    protected $casts = [
        'type' => QueryContextType::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];


    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
