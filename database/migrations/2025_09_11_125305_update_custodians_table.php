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
            $table->unsignedBigInteger('gateway_team_id')->nullable()->after('name');
            $table->string('gateway_team_name')->nullable()->after('gateway_team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custodians', function (Blueprint $table) {
            $table->dropColumn(['pid', 'gateway_team_id', 'gateway_team_name']);

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
