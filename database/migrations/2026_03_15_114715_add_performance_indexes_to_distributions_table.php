<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('distributions', function (Blueprint $table) {

            $table->index(
                ['collection_id', 'category', 'name', 'created_at', 'id'],
                'dist_collection_category_name_created_id_idx'
            );

            $table->index(
                ['collection_id', 'category', 'concept_id', 'created_at', 'id'],
                'dist_collection_category_concept_created_id_idx'
            );

        });
    }

    public function down(): void
    {
        Schema::table('distributions', function (Blueprint $table) {

            $table->dropIndex('dist_collection_category_name_created_id_idx');

            $table->dropIndex('dist_collection_category_concept_created_id_idx');

        });
    }
};
