<?php

use App\Enums\DistributionQueryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->enum(
                'query_type',
                array_map(fn (DistributionQueryType $case) => $case->value, DistributionQueryType::cases())
            )
            ->nullable()
            ->after('definition');
        });
    }

    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->dropColumn('query_type');
        });
    }
};
