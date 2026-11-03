<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('service_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('client_id')->unique();
            $table->string('client_secret');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_accounts');
    }
};
