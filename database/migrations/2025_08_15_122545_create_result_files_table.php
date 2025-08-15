<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('result_files', function (Blueprint $t) {
            $t->id();
            $t->string('pid', 64)->nullable()->unique();

            $t->foreignId('task_id')->constrained()->cascadeOnDelete();
            $t->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();

            $t->string('path');

            $t->string('file_name');
            $t->string('file_type')->nullable();
            $t->text('file_description')->nullable();

            $t->enum('status', ['queued', 'processing', 'done', 'failed'])->default('queued');
            $t->unsignedBigInteger('rows_processed')->default(0);
            $t->text('error')->nullable();

            $t->timestamps();

            $t->index(['task_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_files');
    }
};
