<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('lsoa_centroids', function (Blueprint $table) {
            $table->string('lsoa_code', 12)->primary();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 11, 7);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lsoa_centroids');
    }
};
