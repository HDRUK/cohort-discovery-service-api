<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->unsignedBigInteger('custodian_id_new')->nullable()->after('pid');
        });

        DB::statement('UPDATE collections SET custodian_id_new = custodian_id');

        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('custodian_id');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->renameColumn('custodian_id_new', 'custodian_id');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->foreign('custodian_id')->references('id')->on('custodians');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropForeign(['custodian_id']);
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->unsignedTinyInteger('custodian_id_old')->nullable()->after('pid');
        });

        DB::statement('UPDATE collections SET custodian_id_old = custodian_id');

        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('custodian_id');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->renameColumn('custodian_id_old', 'custodian_id');
        });
    }
};
