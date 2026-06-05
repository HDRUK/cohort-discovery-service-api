<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('postcode_lookup', function (Blueprint $table) {
            $table->string('postcode', 8)->primary();
            $table->string('lsoa21cd', 12)->nullable()->index();
            $table->string('lsoa21nm', 120)->nullable();
            $table->string('ladcd', 12)->nullable();
            $table->string('ladnm', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postcode_lookup');
    }
};
