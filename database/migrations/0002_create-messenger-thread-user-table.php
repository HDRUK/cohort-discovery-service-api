<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create(config('laravel-messenger.thread_users_table_name'), function (Blueprint $table) {
            $table->timestamps();
            $table->foreignId('thread_id')->constrained(config('laravel-messenger.threads_table_name'))->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('laravel-messenger.thread_users_table_name'));
    }
};
