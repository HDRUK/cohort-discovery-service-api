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
        Schema::connection('omop')->create('concept', function (Blueprint $table) {
            $table->unsignedBigInteger('concept_id')->primary();
            $table->string('concept_name');
            $table->string('domain_id');
            $table->string('vocabulary_id');
            $table->string('concept_class_id');
            $table->string('standard_concept')->nullable();
            $table->string('concept_code')->nullable();
            $table->dateTime('valid_start_date')->nullable();
            $table->dateTime('valid_end_date')->nullable();
            $table->string('invalid_reason')->nullable();
            // no timestamps – model has $timestamps = false
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('omop')->dropIfExists('concept');
    }
};
