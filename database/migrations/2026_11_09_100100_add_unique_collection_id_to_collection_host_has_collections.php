<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('collection_host_has_collections', function (Blueprint $table) {
            $table->unique('collection_id', 'collection_host_has_collections_collection_id_unique');
            $table->dropIndex('collection_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('collection_host_has_collections', function (Blueprint $table) {
            $table->index('collection_id', 'collection_id_idx');
            $table->dropUnique('collection_host_has_collections_collection_id_unique');
        });
    }
};
