<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('attempt');

            $table->string('worker_id')->nullable()->index();


            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();


            $table->string('result_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(['task_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_runs');
    }
};
