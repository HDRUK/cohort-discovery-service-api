<?php

use App\Services\Collections\CollectionSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('url');
            $table->string('metadata_schema')->nullable()->after('metadata');
        });

        $schema = app(CollectionSchema::class);

        DB::table('collections')
            ->select(['id', 'name', 'description', 'url'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($schema) {
                foreach ($rows as $row) {
                    DB::table('collections')->where('id', $row->id)->update([
                        'metadata' => json_encode($schema->withColumns([], (array) $row)),
                        'metadata_schema' => $schema->id(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn(['metadata', 'metadata_schema']);
        });
    }
};
