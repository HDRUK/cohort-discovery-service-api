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
        Schema::create('nlp_query_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->mediumText('query');
            $table->mediumText('nlp_extracted');
            $table->bigInteger('user_id');

            $table->index('user_id', 'idx_nlp_query_logs_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nlp_query_logs');
    }
};
