<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('user_has_workgroups', function (Blueprint $table) {
            $table->unique(['user_id', 'workgroup_id'], 'uhw_user_workgroup_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_has_workgroups', function (Blueprint $table) {
            $table->dropUnique('uhw_user_workgroup_unique');
        });
    }
};
