<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="CollectionHostHasCollection",
 *     type="object",
 *     title="CollectionHostHasCollection",
 *     description="A pivot model linking a CollectionHost to a Collection",
 *     required={"collection_host_id","collection_id"},
 *     @OA\Property(property="collection_host_id", type="integer", example=3, description="FK to collection_hosts"),
 *     @OA\Property(property="collection_id", type="integer", example=10, description="FK to collections")
 * )
 */
class CollectionHostHasCollection extends Model
{
    protected $table = 'collection_host_has_collections';

    public $timestamps = false;

    protected $fillable = [
        'collection_host_id',
        'collection_id',
    ];
}
