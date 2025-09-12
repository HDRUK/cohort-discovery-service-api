<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('concept_set_has_concept', function (Blueprint $table) {
            $table->foreignId('concept_set_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('concept_id');
            $table->primary(['concept_set_id', 'concept_id']);
            $table->index('concept_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concept_set_has_concept');
    }
};
