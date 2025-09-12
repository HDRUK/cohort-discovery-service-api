<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('concept_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('domain', 255);
            $table->string('name', 255);
            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'domain', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concept_sets');
    }
};
