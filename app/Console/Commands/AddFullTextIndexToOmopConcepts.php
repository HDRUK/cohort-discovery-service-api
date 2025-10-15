<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFullTextIndexToOmopConcepts extends Command
{
    protected $signature = 'app:add-full-text-index-to-omop-concepts';
    protected $description = 'Adds a fulltext index to the omop.concept table';

    public function handle()
    {
        $connection = DB::connection('omop');
        $schema = Schema::connection('omop');

        if (!$schema->hasTable('concept')) {
            $this->error('Table omop.concept doesn\'t exist on connection [omop]');
            return self::FAILURE;
        }

        if ($this->hasFullTextIndex($connection, 'concept', 'ft_concept_name')) {
            $this->warn('Table omop.concept already has ft_concept_name index');
            return self::SUCCESS;
        }

        $schema->table('concept', function (Blueprint $table) {
            $table->fullText('concept_name', 'ft_concept_name');
        });

        $this->info('FullText index successfully created on omop.concept');
        return self::SUCCESS;
    }

    private function hasFullTextIndex($connection, string $table, string $index): bool
    {
        return collect(
            $connection->select('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ?', [$index])
        )->isNotEmpty();
    }
}
