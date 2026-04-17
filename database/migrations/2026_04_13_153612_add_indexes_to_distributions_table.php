<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM distributions'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        Schema::table('distributions', function (Blueprint $table) use ($indexes) {
            if (! in_array('idx_distributions_concept_id', $indexes, true)) {
                $table->index('concept_id', 'idx_distributions_concept_id');
            }

            if (! in_array('idx_distributions_result_file_id', $indexes, true)) {
                $table->index('result_file_id', 'idx_distributions_result_file_id');
            }

            if (! in_array('idx_distributions_category', $indexes, true)) {
                $table->index('category', 'idx_distributions_category');
            }

            if (! in_array('idx_distributions_task_id', $indexes, true)) {
                $table->index('task_id', 'idx_distributions_task_id');
            }

            if (! in_array('idx_distributions_task_concept_collection', $indexes, true)) {
                $table->index(
                    ['task_id', 'concept_id', 'collection_id'],
                    'idx_distributions_task_concept_collection'
                );
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM distributions'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        Schema::table('distributions', function (Blueprint $table) use ($indexes) {
            if (in_array('idx_distributions_task_concept_collection', $indexes, true)) {
                $table->dropIndex('idx_distributions_task_concept_collection');
            }

            if (in_array('idx_distributions_task_id', $indexes, true)) {
                $table->dropIndex('idx_distributions_task_id');
            }

            if (in_array('idx_distributions_category', $indexes, true)) {
                $table->dropIndex('idx_distributions_category');
            }

            if (in_array('idx_distributions_result_file_id', $indexes, true)) {
                $table->dropIndex('idx_distributions_result_file_id');
            }

            if (in_array('idx_distributions_concept_id', $indexes, true)) {
                $table->dropIndex('idx_distributions_concept_id');
            }
        });
    }
};
