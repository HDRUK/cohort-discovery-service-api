<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('distributions', function (Blueprint $table) {
            $table->unsignedBigInteger('result_file_id')->nullable()->after('task_id');
            $table->index(['task_id', 'result_file_id'], 'idx_dist_task_file');

            $table->unique(
                ['task_id', 'result_file_id', 'category', 'name'],
                'uniq_dist_task_file_cat_name'
            );
        });
    }

    public function down(): void
    {
        Schema::table('distributions', function (Blueprint $table) {
            $table->dropUnique('uniq_dist_task_file_cat_name');
            $table->dropIndex('idx_dist_task_file');
            $table->dropColumn('result_file_id');
        });
    }
};
