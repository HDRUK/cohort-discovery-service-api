<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('collection_metadata', function (Blueprint $table) {
            $table->tinyInteger('supports_death_filter')->unsigned()->nullable()->after('death_filter');
            $table->tinyInteger('supports_location_filter')->unsigned()->nullable()->after('supports_death_filter');
        });
    }

    public function down(): void
    {
        Schema::table('collection_metadata', function (Blueprint $table) {
            $table->dropColumn(['supports_death_filter', 'supports_location_filter']);
        });
    }
};
