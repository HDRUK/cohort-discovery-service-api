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
        Schema::create('collection_config', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('collection_id')->constrained()->onDelete('cascade');
            $table->tinyInteger('run_time_hour')->between(0, 23);
            $table->tinyInteger('run_time_minute')->between(0, 59);
            $table->tinyInteger('frequency_mode')->default(1); // Default to weekly
            $table->tinyInteger('run_time_frequency')->default(1); // Default to Sunday's
            $table->tinyInteger('enabled')->default(1);
            $table->string('type', 1);

            $table->index('type', 'idx_collection_config_type');
            $table->index('frequency_mode', 'idx_collection_config_frequency_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_config');
    }
};
