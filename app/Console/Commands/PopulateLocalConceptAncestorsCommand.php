<?php

namespace App\Console\Commands;

use App\Jobs\PopulateLocalConceptAncestors;
use Illuminate\Console\Command;

class PopulateLocalConceptAncestorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Example: php artisan concepts:populate-ancestors
     */
    protected $signature = 'concepts:populate-ancestors';

    /**
     * The console command description.
     */
    protected $description = 'Populate the local_concept_ancestors table based on OMOP concept relationships.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Dispatching PopulateLocalConceptAncestors job...');

        PopulateLocalConceptAncestors::dispatch();

        $this->info('Job dispatched successfully.');

        return self::SUCCESS;
    }
}
