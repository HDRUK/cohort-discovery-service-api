<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionConfig;
use DB;
use Tests\TestCase;

class NameCollectionConfigTypeValuesMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_11_03_100000_name_collection_config_type_values.php';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Collection::truncate();
        CollectionConfig::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_it_renames_letter_types_and_reverses_them(): void
    {
        $this->disableObservers();

        $collection = Collection::factory()->create();
        $migration = require database_path(self::MIGRATION);

        $migration->down();

        $genericId = $this->insertConfig($collection->id, 'a');
        $demographicsId = $this->insertConfig($collection->id, 'b');

        $migration->up();

        $this->assertDatabaseHas('collection_config', ['id' => $genericId, 'type' => 'GENERIC']);
        $this->assertDatabaseHas('collection_config', ['id' => $demographicsId, 'type' => 'DEMOGRAPHICS']);

        $migration->down();

        $this->assertDatabaseHas('collection_config', ['id' => $genericId, 'type' => 'a']);
        $this->assertDatabaseHas('collection_config', ['id' => $demographicsId, 'type' => 'b']);

        $migration->up();
    }

    private function insertConfig(int $collectionId, string $type): int
    {
        return DB::table('collection_config')->insertGetId([
            'collection_id' => $collectionId,
            'run_time_hour' => 23,
            'run_time_minute' => 59,
            'frequency_mode' => 1,
            'run_time_frequency' => 6,
            'enabled' => 1,
            'type' => $type,
        ]);
    }
}
