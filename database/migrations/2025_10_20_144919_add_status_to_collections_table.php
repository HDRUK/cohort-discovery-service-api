<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->tinyInteger('status')->default(0);

            $table->index('status', 'idx_collections_status');
            $table->index('type', 'idx_collections_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropIndex('idx_collections_status');
            $table->dropIndex('idx_collections_type');
            $table->dropColumn('status');
        });
    }
};
