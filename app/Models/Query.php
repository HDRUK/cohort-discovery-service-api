<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Hdruk\LaravelSearchAndFilter\Traits\Filter;
use App\Rules\IdOrUuid;
use App\Enums\TaskType;

use App\Traits\Downloadable;

/**
 * @property int $id
 * @property string $name
 * @property array $definition
 */
class Query extends Model
{
    use HasFactory;
    use Search;
    use Filter;
    use Downloadable;

    public $timestamps = false;

    protected $fillable = [
        'pid',
        'name',
        'user_id',
        'definition',
        'created_at',
    ];

    protected $casts = [
        'definition' => 'array',
        'created_at' => 'datetime',
    ];

    protected static array $searchableColumns = [
        'pid',
        'name',
        'definition',
    ];

    protected static array $sortableColumns = [
        'name',
        'created_at',
    ];

    public static function downloadableFields(): array
    {
        return [
            'id',
            'pid',
            'name',
            'definition',
            'created_at',
        ];
    }

    public function getValidationRules(string $context): array
    {
        return match(strtolower($context)) {
            'index' => [],
            'show' => [
                'key' => [
                    'required',
                    new IdOrUuid()
                ],
            ],
            'store' => [
                'name' => 'required|string|min:3|max:255',
                'definition' => 'required|array',
                'collection_filter' => 'nullable|array',
                'task_type' => [
                    'required',
                    new Enum(TaskType::class),
                ],
            ],
            'update' => [
                'name' => 'sometimes|string|min:3|max:255',
                'definition' => 'sometimes|array',
            ],
            'delete' => [
                'key' => [
                    'required',
                    new IdOrUuid()
                ],
            ],
            default => [],
        };
    }

    protected static function booted(): void
    {
        static::creating(function ($query) {
            $query->pid = $query->pid ?? (string) Str::uuid();
        });
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'query_id');
    }
}
