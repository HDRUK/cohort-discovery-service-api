<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('collection_config_runs', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('collection_config_id');
            $table->bigInteger('query_id')->nullable();
            $table->bigInteger('task_id')->nullable();
            $table->timestamp('ran_at');
            $table->tinyInteger('successful');
            $table->text('errors')->nullable();

            $table->index('collection_config_id', 'idx_collection_config_runs_collection_config_id');
            $table->index('query_id', 'idx_collection_config_runs_query_id');
            $table->index('task_id', 'idx_collection_config_runs_task_id');
            $table->index('ran_at', 'idx_collection_config_runs_ran_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_config_runs');
    }
};
