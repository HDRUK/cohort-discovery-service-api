<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('collection_activity_logs', function (Blueprint $table) {
            $table->char('task_type', 1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_activity_logs', function (Blueprint $table) {
            $table->dropColumn('task_type');
        });
    }
};
