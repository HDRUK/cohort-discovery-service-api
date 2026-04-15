<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('collection_activity_logs', function (Blueprint $table) {
            $table->index([
                'collection_id',
                'task_type',
                'created_at',
                'id'
            ], 'collection_activity_logs_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('collection_activity_logs', function (Blueprint $table) {
            $table->dropIndex('collection_activity_logs_lookup_idx');
        });
    }
};
