<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('collection_metadata', function (Blueprint $table) {
            $table->tinyInteger('death_filter')->unsigned()->nullable()->after('datamodel');
        });
    }

    public function down(): void
    {
        Schema::table('collection_metadata', function (Blueprint $table) {
            $table->dropColumn('death_filter');
        });
    }
};
