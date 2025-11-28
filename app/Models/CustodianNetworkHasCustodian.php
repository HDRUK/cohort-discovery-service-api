<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="CustodianNetworkHasCustodian",
 *     type="object",
 *     title="CustodianNetworkHasCustodian",
 *     description="Pivot linking a CustodianNetwork to a Custodian",
 *     required={"network_id","custodian_id"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="network_id", type="integer", example=2, description="FK to custodian_networks"),
 *     @OA\Property(property="custodian_id", type="integer", example=3, description="FK to custodians"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class CustodianNetworkHasCustodian extends Model
{
    public $table = 'custodian_network_has_custodians';
    public $timestamps = true;

    protected $fillable = [
        'network_id',
        'custodian_id',
    ];
}
