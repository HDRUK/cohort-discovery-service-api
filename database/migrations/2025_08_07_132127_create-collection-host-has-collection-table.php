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
        Schema::create('collection_host_has_collections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('collection_host_id');
            $table->unsignedBigInteger('collection_id');

            $table->index('collection_host_id', 'collection_host_id_idx');
            $table->index('collection_id', 'collection_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_host_has_collections');
    }
};
