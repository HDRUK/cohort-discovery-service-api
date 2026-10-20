<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('collection_ping_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->char('task_type', 1);

            $table->dateTime('bucket_minute');
            $table->unsignedInteger('n')->default(0);

            $table->dateTime('first_ping_at');
            $table->dateTime('last_ping_at');

            $table->unique(
                ['collection_id', 'task_type', 'bucket_minute'],
                'cpb_collection_type_minute_uniq'
            );

            $table->index(['collection_id', 'bucket_minute'], 'cpb_collection_minute_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_ping_buckets');
    }
};
