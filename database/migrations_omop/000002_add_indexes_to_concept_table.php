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
        Schema::table('concept', function (Blueprint $table) {
            $table->index('domain_id', 'idx_domain_id');
            $table->index('vocabulary_id', 'idx_vocabulary_id');
            $table->index('concept_class_id', 'idx_concept_class_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('concept', function (Blueprint $table) {
            $table->dropIndex('idx_domain_id');
            $table->dropIndex('idx_vocabulary_id');
            $table->dropIndex('idx_concept_class_id');
        });
    }
};
