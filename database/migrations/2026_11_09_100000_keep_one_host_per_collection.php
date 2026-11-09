<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class () extends Migration {
    public function up(): void
    {
        $newestLinkIds = DB::table('collection_host_has_collections')
            ->selectRaw('MAX(id)')
            ->groupBy('collection_id');

        $staleLinks = DB::table('collection_host_has_collections')
            ->whereNotIn('id', $newestLinkIds)
            ->get();

        foreach ($staleLinks as $link) {
            Log::warning('Removed extra collection host link', [
                'collection_id' => $link->collection_id,
                'collection_host_id' => $link->collection_host_id,
            ]);
        }

        DB::table('collection_host_has_collections')
            ->whereIn('id', $staleLinks->pluck('id'))
            ->delete();
    }

    public function down(): void
    {
    }
};
