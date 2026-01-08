<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('leased_by')->nullable()->index();
            $table->dateTime('leased_until')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['leased_by']);
            $table->dropIndex(['leased_until']);
            $table->dropColumn(['leased_by', 'leased_until']);
        });
    }
};
