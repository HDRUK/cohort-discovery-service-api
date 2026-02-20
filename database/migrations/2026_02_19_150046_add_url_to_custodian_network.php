<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('custodian_networks', function (Blueprint $table) {
            $table->string('url')->nullable()->after('name');
        });

        Schema::table('custodian_networks', function (Blueprint $table) {
            $table->unique('name', 'custodian_networks_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('custodian_networks', function (Blueprint $table) {
            $table->dropUnique('custodian_networks_name_unique');
            $table->dropColumn('url');
        });
    }
};
