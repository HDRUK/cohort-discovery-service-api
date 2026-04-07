<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('collection_metadata', function (Blueprint $table) {
            $table->id();

            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('result_file_id')->nullable()->constrained()->nullOnDelete();

            $table->string('biobank')->nullable();
            $table->string('protocol')->nullable();
            $table->string('os')->nullable();
            $table->string('bclink')->nullable();
            $table->string('datamodel')->nullable();
            $table->string('rounding')->nullable();
            $table->string('threshold')->nullable();

            $table->timestamps();

            $table->index(['collection_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_metadata');
    }
};
