<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->boolean('location_enabled')
                ->default(false)
                ->after('is_synthetic');

            $table->boolean('death_enabled')
                ->default(false)
                ->after('location_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop one column per call so the rollback is safe on SQLite too,
        // which cannot drop multiple columns in a single statement.
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('location_enabled');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('death_enabled');
        });
    }
};
