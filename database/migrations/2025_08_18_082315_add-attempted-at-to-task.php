<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('attempted_at')->after('created_at')->nullable();
            $table->integer('attempts')->after('attempted_at')->default(0);
            $table->timestamp('failed_at')->after('completed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('attempted_at');
            $table->dropColumn('attempts');
            $table->dropColumn('failed_at');
        });
    }
};
