<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regression_test_collection', function (Blueprint $table) {
            $table->foreignId('regression_test_id')->constrained('regression_tests')->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->integer('expected_result')->nullable();
            $table->primary(['regression_test_id', 'collection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regression_test_collection');
    }
};
