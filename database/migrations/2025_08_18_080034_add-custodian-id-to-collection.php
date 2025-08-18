<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->unsignedTinyInteger('custodian_id')->after('pid');
        });

        $firstCustodianId = DB::table('custodians')->orderBy('id')->value('id');

        if (!is_null($firstCustodianId)) {
            DB::table('collections')
                ->where('custodian_id', 0)
                ->update(['custodian_id' => $firstCustodianId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('custodian_id');
        });
    }
};
