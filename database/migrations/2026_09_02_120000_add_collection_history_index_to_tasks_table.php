<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The task history endpoint reads one collection's tasks over a time range,
     * newest first. The foreign key's index on collection_id alone leaves the range
     * filter and the sort to be done on the matched rows; this composite covers
     * both.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['collection_id', 'created_at'], 'tasks_collection_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_collection_id_created_at_index');
        });
    }
};
