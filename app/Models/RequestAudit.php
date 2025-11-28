<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="RequestAudit",
 *     type="object",
 *     title="RequestAudit",
 *     description="Records of incoming HTTP requests for auditing and diagnostics.",
 *     required={"method","uri","status"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="user_id", type="integer", nullable=true, example=2, description="FK to the authenticated user (if any)"),
 *     @OA\Property(property="method", type="string", example="GET", description="HTTP method used by the request"),
 *     @OA\Property(property="uri", type="string", example="/api/v1/queries", description="Request URI"),
 *     @OA\Property(property="status", type="integer", example=200, description="HTTP response status code"),
 *     @OA\Property(property="ip_address", type="string", format="ipv4", nullable=true, example="203.0.113.10", description="Origin IP address"),
 *     @OA\Property(property="user_agent", type="string", nullable=true, example="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)", description="User agent header"),
 *     @OA\Property(property="payload", type="string", nullable=true, example="", description="JSON-serialized request payload/body"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class RequestAudit extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'method',
        'uri',
        'status',
        'ip_address',
        'user_agent',
        'payload',
    ];
}
