<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-minute counts of collection host polls ("pings").
 *
 * `collection_activity_logs` holds a single row per (collection, task_type) that
 * is touched on every poll, so it answers "is this host alive right now?" and
 * nothing else. This table keeps a minute-resolution history instead: one row
 * per (collection, task_type, minute), incremented in place.
 *
 * Minute is the base resolution; hour/day/week/month bins are derived at query
 * time by CollectionHealthService, so there are no rollup tables to keep in step.
 * Volume is independent of poll cadence - ~2,880 rows/day/collection - and is
 * bounded by PruneCollectionPingBucketsJob.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('collection_ping_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->char('task_type', 1);

            // UTC, truncated to the minute - the bucket this row counts.
            $table->dateTime('bucket_minute');
            $table->unsignedInteger('n')->default(0);

            // First and last ping seen inside the bucket. Recovers gap detection
            // at bucket edges, which a bare count would lose.
            $table->dateTime('first_ping_at');
            $table->dateTime('last_ping_at');

            // The upsert key: makes INSERT ... ON DUPLICATE KEY UPDATE atomic, and
            // serves a single-task-type range scan as a tight seek.
            $table->unique(
                ['collection_id', 'task_type', 'bucket_minute'],
                'cpb_collection_type_minute_uniq'
            );

            // The health query ranges on bucket_minute across BOTH task types, so
            // task_type is unconstrained and the unique index above cannot range on
            // its third column. This one keeps that query a range scan.
            $table->index(['collection_id', 'bucket_minute'], 'cpb_collection_minute_idx');

            // Serves the retention sweep, which deletes across all collections by age.
            $table->index('bucket_minute', 'cpb_minute_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_ping_buckets');
    }
};
