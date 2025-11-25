<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Hdruk\LaravelSearchAndFilter\Traits\Search;

/**
 * @OA\Schema(
 *     schema="Custodian",
 *     type="object",
 *     title="Custodian",
 *     required={"name", "street_address", "city", "postal_code", "country"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Health Data Custodian"),
 *     @OA\Property(property="street_address", type="string", example="123 Main St"),
 *     @OA\Property(property="city", type="string", example="London"),
 *     @OA\Property(property="postal_code", type="string", example="SW1A 1AA"),
 *     @OA\Property(property="country", type="string", example="United Kingdom"),
 *     @OA\Property(property="url", type="string", format="uri", example="https://custodian.org"),
 *     @OA\Property(property="email", type="string", format="email", example="info@custodian.org"),
 *     @OA\Property(property="phone", type="string", example="+44 1234 567890"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class Custodian extends Model
{
    /** @use HasFactory<\Database\Factories\CustodianFactory> */
    use HasFactory;
    use Search;

    public $table = 'custodians';

    public $timestamps = true;

    protected $fillable = [
        'pid',
        'name',
        'gateway_team_id',
        'gateway_team_name',
    ];

    protected static array $searchableColumns = [
        'name',
    ];

    protected static array $sortableColumns = [
        'name',
    ];

    protected static function booted()
    {
        static::creating(function (Custodian $custodian) {
            if (empty($custodian->pid)) {
                $custodian->pid = (string) Str::uuid();
            }
        });
    }

    public function hosts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CollectionHost::class, 'custodian_id');
    }

    public function network(): \Illuminate\Database\Eloquent\Relations\HasOneThrough
    {
        return $this->hasOneThrough(
            CustodianNetwork::class,
            CustodianNetworkHasCustodian::class,
            'custodian_id',
            'id',
            'id',
            'network_id',
        );
    }
}
