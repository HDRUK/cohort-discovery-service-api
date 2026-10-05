<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('workgroups', function (Blueprint $table) {
            $table->dropUnique('workgroups_claim_value_unique');
            $table->dropColumn('claim_value');
        });
    }

    public function down(): void
    {
        Schema::table('workgroups', function (Blueprint $table) {
            $table->string('claim_value')->nullable()->unique()->after('active');
        });
    }
};
