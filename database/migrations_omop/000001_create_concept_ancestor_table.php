<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Use the omop connection instead of the default.
     *
     * @var string
     */
    protected $connection = 'omop';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('omop')->create('concept_ancestor', function (Blueprint $table) {
            $table->unsignedBigInteger('ancestor_concept_id');
            $table->unsignedBigInteger('descendant_concept_id');
            $table->integer('min_levels_of_separation');
            $table->integer('max_levels_of_separation');

            $table->primary(['ancestor_concept_id', 'descendant_concept_id']);

            $table->index('ancestor_concept_id');
            $table->index('descendant_concept_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('omop')->dropIfExists('concept_ancestor');
    }
};
