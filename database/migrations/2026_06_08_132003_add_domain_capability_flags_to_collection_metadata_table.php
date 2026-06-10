<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('collection_metadata', function (Blueprint $table) {
            $table->unsignedTinyInteger('supports_condition')->nullable()->after('supports_location_filter');
            $table->unsignedTinyInteger('supports_drug')->nullable()->after('supports_condition');
            $table->unsignedTinyInteger('supports_observation')->nullable()->after('supports_drug');
            $table->unsignedTinyInteger('supports_measurement')->nullable()->after('supports_observation');
            $table->unsignedTinyInteger('supports_demographics')->nullable()->after('supports_measurement');
            $table->unsignedTinyInteger('location_has_coordinates')->nullable()->after('supports_demographics');
        });
    }

    public function down(): void
    {
        Schema::table('collection_metadata', function (Blueprint $table) {
            $table->dropColumn([
                'supports_condition',
                'supports_drug',
                'supports_observation',
                'supports_measurement',
                'supports_demographics',
                'location_has_coordinates',
            ]);
        });
    }
};
