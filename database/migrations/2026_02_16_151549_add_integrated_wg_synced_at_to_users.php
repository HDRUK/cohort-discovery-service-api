<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        //keep track of when (if) syncing with the integrated workgroups took place
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('integrated_wg_synced_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('integrated_wg_synced_at');
        });
    }
};
