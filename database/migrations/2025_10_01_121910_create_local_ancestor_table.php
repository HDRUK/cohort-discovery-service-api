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
        Schema::create('concept_ancestors', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_concept_id');
            $table->unsignedBigInteger('child_concept_id');

            $table->primary(['parent_concept_id', 'child_concept_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('concept_ancestors', function (Blueprint $table) {
            Schema::dropIfExists('concept_ancestors');
        });
    }
};
