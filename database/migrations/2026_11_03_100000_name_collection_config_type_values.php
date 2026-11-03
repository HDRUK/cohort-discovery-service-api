<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('collection_config', function (Blueprint $table) {
            $table->string('type', 32)->change();
        });

        DB::table('collection_config')->where('type', 'a')->update(['type' => 'GENERIC']);
        DB::table('collection_config')->where('type', 'b')->update(['type' => 'DEMOGRAPHICS']);
    }

    public function down(): void
    {
        DB::table('collection_config')->where('type', 'GENERIC')->update(['type' => 'a']);
        DB::table('collection_config')->where('type', 'DEMOGRAPHICS')->update(['type' => 'b']);

        Schema::table('collection_config', function (Blueprint $table) {
            $table->string('type', 1)->change();
        });
    }
};
