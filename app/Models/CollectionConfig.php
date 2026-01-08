<?php

namespace App\Models;

use App\Contracts\ValidatableModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @OA\Schema(
 *     schema="CollectionConfig",
 *     type="object",
 *     title="CollectionConfig",
 *     description="Configuration settings for scheduled distribution runs for a Collection",
 *     required={"collection_id"},
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="collection_id", type="integer", example=10),
 *     @OA\Property(property="run_time_hour", type="integer", nullable=true, example=2, description="Hour of day for distribution run (0-23)"),
 *     @OA\Property(property="run_time_minute", type="integer", nullable=true, example=30, description="Minute of hour for distribution run (0-59)"),
 *     @OA\Property(property="frequency_mode", type="string", nullable=true, example="daily", description="Frequency mode (e.g. daily, weekly, monthly)"),
 *     @OA\Property(property="run_time_frequency", type="integer", nullable=true, example=1, description="Frequency multiplier (e.g. every N days/weeks/months)"),
 *     @OA\Property(property="enabled", type="boolean", example=true, description="Whether scheduled distributions are enabled"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class CollectionConfig extends Model implements ValidatableModel
{
    /** @use HasFactory<\Database\Factories\CollectionConfigFactory> */
    use HasFactory;

    public $table = 'collection_config';

    public $timestamps = true;

    protected $fillable = [
        'collection_id',
        'run_time_hour', // 0 - 23
        'run_time_minute', // 0 - 59
        // Can be either:
        // 1 - Weekly
        // 2 - Monthly
        'frequency_mode',
        // Can switch between frequency_mode:
        // When weekly:
        //    - (1-7 Monday to Sunday)
        // When monthly:
        //    - (1-5 week number of the month)
        'run_time_frequency',
        'enabled',
        // Can be either: A - Query, or B - Distribution
        'type',
        'last_run_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'frequency_mode' => 'integer',
        'run_time_frequency' => 'integer',
        'run_time_hour' => 'integer',
        'run_time_minute' => 'integer',
    ];

    public function getValidationRules(string $context): array
    {
        return match (strtolower($context)) {
            'index' => [],
            'show' => [
                'id' => 'required|exists:collection_config,id',
            ],
            'store' => [
                'collection_id' => 'required|exists:collections,id',
                'run_time_hour' => 'required|integer|min:0|max:23',
                'run_time_minute' => 'required|integer|min:0|max:59',
                'frequency_mode' => 'required|integer',
                'run_time_frequency' => 'required|integer',
                'enabled' => 'required|integer',
                'type' => 'required|string',
            ],
            'update' => [
                'id' => 'required|exists:collection_config,id',
                'collection_id' => 'sometimes|exists:collections,id',
                'run_time_hour' => 'sometimes|integer|min:0|max:23',
                'run_time_minute' => 'sometimes|integer|min:0|max:59',
                'frequency_mode' => 'sometimes|integer',
                'run_time_frequency' => 'sometimes|integer',
                'enabled' => 'sometimes|integer',
                'type' => 'sometimes|string',
            ],
            'delete' => [
                'id' => 'required|exists:collection_config,id',
            ],
            default => [],
        };
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
