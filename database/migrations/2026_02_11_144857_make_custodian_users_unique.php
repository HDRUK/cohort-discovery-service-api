<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('custodian_has_users', function (Blueprint $table) {
            $table->unique(['user_id', 'custodian_id'], 'custodian_has_users_user_custodian_unique');
        });
    }

    public function down(): void
    {
        Schema::table('custodian_has_users', function (Blueprint $table) {
            $table->dropUnique('custodian_has_users_user_custodian_unique');
        });
    }
};
