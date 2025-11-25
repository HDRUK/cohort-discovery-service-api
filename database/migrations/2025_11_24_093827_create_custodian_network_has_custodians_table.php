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
        Schema::create('custodian_network_has_custodians', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->bigInteger('network_id');
            $table->bigInteger('custodian_id');

            $table->index('network_id', 'idx_custodian_network_network_id');
            $table->index('custodian_id', 'idx_custodian_network_custodian_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custodian_network_has_custodians');
    }
};
