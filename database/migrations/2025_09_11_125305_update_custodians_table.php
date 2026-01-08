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
        Schema::table('custodians', function (Blueprint $table) {
            $table->dropColumn([
                'street_address',
                'city',
                'postal_code',
                'country',
                'url',
                'email',
                'phone',
                'user_id',
            ]);
            $table->uuid('pid')->unique()->after('id');
            $table->string('external_custodian_id')->nullable()->after('name');
            $table->string('external_custodian_name')->nullable()->after('external_custodian_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custodians', function (Blueprint $table) {
            $table->dropColumn(['pid', 'external_custodian_id', 'external_custodian_name']);

            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->string('url')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
        });
    }
};
