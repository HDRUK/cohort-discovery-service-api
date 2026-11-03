<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('custodians', function (Blueprint $table) {
            $table->unique('external_custodian_id');
        });
    }

    public function down(): void
    {
        Schema::table('custodians', function (Blueprint $table) {
            $table->dropUnique(['external_custodian_id']);
        });
    }
};
