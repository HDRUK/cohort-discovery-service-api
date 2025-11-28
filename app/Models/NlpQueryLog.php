<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="NlpQueryLog",
 *     type="object",
 *     title="NlpQueryLog",
 *     description="Records of user-submitted natural language queries and the NLP-extracted entities returned for logging/diagnostics.",
 *     required={"query"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="query", type="string", example="diabetes and hypertension", description="Original user query string"),
 *     @OA\Property(property="nlp_extracted", type="string", example="", description="JSON-serialized NLP entities extracted from the query"),
 *     @OA\Property(property="user_id", type="integer", nullable=true, example=2, description="FK to the user who submitted the query"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class NlpQueryLog extends Model
{
    public $table = 'nlp_query_logs';
    public $timestamps = true;

    protected $fillable = [
        'query',
        'nlp_extracted',
        'user_id',
    ];
}
