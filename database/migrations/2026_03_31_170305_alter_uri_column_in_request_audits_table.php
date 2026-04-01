<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('request_audits', function (Blueprint $table) {
            $table->text('uri')->change();
        });
    }

    public function down(): void
    {
        Schema::table('request_audits', function (Blueprint $table) {
            $table->string('uri')->change();
        });
    }
};
