<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('latest_distributions_materialised', function (Blueprint $table) {
            $table->index(['concept_id', 'domain_id', 'collection_id', 'count'], 'ldm_concept_domain_collection_count_idx');
        });
    }

    public function down(): void
    {
        Schema::table('latest_distributions_materialised', function (Blueprint $table) {
            $table->dropIndex('ldm_concept_domain_collection_count_idx');
        });
    }
};
