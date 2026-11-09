<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionHost;
use App\Models\CollectionHostHasCollection;
use DB;
use Tests\TestCase;

class KeepOneHostPerCollectionMigrationTest extends TestCase
{
    private const DATA_MIGRATION = 'migrations/2026_11_09_100000_keep_one_host_per_collection.php';
    private const UNIQUE_MIGRATION = 'migrations/2026_11_09_100100_add_unique_collection_id_to_collection_host_has_collections.php';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Collection::truncate();
        CollectionHost::truncate();
        CollectionHostHasCollection::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_it_keeps_only_the_newest_host_link_for_each_collection(): void
    {
        $uniqueMigration = require database_path(self::UNIQUE_MIGRATION);
        $uniqueMigration->down();

        $duplicated = Collection::factory()->create();
        $single = Collection::factory()->create();
        $hosts = CollectionHost::factory(3)->create();

        $this->link($duplicated, $hosts[0]);
        $this->link($single, $hosts[0]);
        $this->link($duplicated, $hosts[1]);
        $newest = $this->link($duplicated, $hosts[2]);

        (require database_path(self::DATA_MIGRATION))->up();
        $uniqueMigration->up();

        $this->assertDatabaseCount('collection_host_has_collections', 2);
        $this->assertDatabaseHas('collection_host_has_collections', [
            'id' => $newest->id,
            'collection_id' => $duplicated->id,
            'collection_host_id' => $hosts[2]->id,
        ]);
        $this->assertDatabaseHas('collection_host_has_collections', [
            'collection_id' => $single->id,
            'collection_host_id' => $hosts[0]->id,
        ]);
    }

    private function link(Collection $collection, CollectionHost $host): CollectionHostHasCollection
    {
        return CollectionHostHasCollection::create([
            'collection_host_id' => $host->id,
            'collection_id' => $collection->id,
        ]);
    }
}
