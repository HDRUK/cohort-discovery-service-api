<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create(config('laravel-messenger.messages_table_name'), function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('thread_id')->constrained(config('laravel-messenger.threads_table_name'))->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();

            $table->text('body');
            $table->boolean('is_read')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('laravel-messenger.messages_table_name'));
    }
};
