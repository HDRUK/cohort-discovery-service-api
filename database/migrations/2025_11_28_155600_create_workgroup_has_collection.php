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
        Schema::create('workgroup_has_collection', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->bigInteger('workgroup_id');
            $table->bigInteger('collection_id');

            $table->index('workgroup_id', 'idx_workgroup_collection_workgroup_id');
            $table->index('collection_id', 'idx_workgroup_collection_collection_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workgroup_has_collection');
    }
};
