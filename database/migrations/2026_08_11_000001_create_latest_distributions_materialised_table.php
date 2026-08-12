<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialised counterpart of the `latest_distributions` VIEW.
 *
 * The view re-runs a cross-database join to the OMOP `concept` table on every
 * read, which MySQL cannot serve from an index. This table is a snapshot of the
 * view, refilled by RefreshLatestDistributionsView whenever the view is rebuilt,
 * so the Term Directory query reads pre-joined, indexed rows instead.
 *
 * Columns mirror the view exactly (see RefreshLatestDistributionsView).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('latest_distributions_materialised', function (Blueprint $table) {
            // `id` mirrors the underlying distribution row id (copied, not generated).
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('collection_id');
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('result_file_id')->nullable();
            $table->unsignedBigInteger('concept_id');
            $table->unsignedInteger('count');
            $table->string('concept_name')->nullable();
            $table->string('reported_domain_id')->nullable();
            $table->string('central_domain_id')->nullable();
            $table->boolean('domain_mismatch')->default(false);
            $table->string('domain_id')->nullable();

            // Serves the Term Directory aggregation: filter by visible collections,
            // group by concept. Trailing `count` makes it covering, so the
            // WHERE collection_id + GROUP BY concept_id + SUM(count) is index-only.
            $table->index(['collection_id', 'concept_id', 'count'], 'ldm_collection_concept_count_idx');
            $table->index('concept_id', 'ldm_concept_idx');
            $table->index('domain_id', 'ldm_domain_idx');

            // Direct count-ordered scans (e.g. top concepts by count on the raw table):
            // a backward index scan + LIMIT with no filesort.
            $table->index('count', 'ldm_count_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('latest_distributions_materialised');
    }
};
