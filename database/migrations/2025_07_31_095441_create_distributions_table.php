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
        Schema::create('distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->onDelete('cascade');
            $table->foreignId('task_id')->nullable()->constrained();

            $table->string('name');
            $table->string('category');
            $table->string('description');
            $table->unsignedInteger('count');

            $table->string('q1')->nullable();
            $table->string('q3')->nullable();
            $table->string('min')->nullable();
            $table->string('max')->nullable();
            $table->string('mean')->nullable();
            $table->string('median')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distributions');
    }
};
