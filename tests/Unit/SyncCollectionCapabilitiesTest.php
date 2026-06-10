<?php

namespace Tests\Unit;

use App\Jobs\SyncCollectionCapabilities;
use App\Models\Collection;
use App\Models\CollectionMetadata;
use App\Models\Custodian;
use App\Models\Distribution;
use App\Models\ResultFile;
use App\Models\Task;
use DB;
use Tests\TestCase;

class SyncCollectionCapabilitiesTest extends TestCase
{
    private Collection $collection;
    private ResultFile $resultFile;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Distribution::truncate();
        CollectionMetadata::truncate();
        ResultFile::truncate();
        Task::truncate();
        Collection::truncate();
        Custodian::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->disableObservers();

        $custodian = Custodian::factory()->create();
        $this->collection = Collection::factory()->create(['custodian_id' => $custodian->id]);

        $task = Task::factory()->create(['collection_id' => $this->collection->id]);

        $this->resultFile = ResultFile::create([
            'pid'           => 'rf_test_' . uniqid(),
            'task_id'       => $task->id,
            'collection_id' => $this->collection->id,
            'path'          => 'test/metadata.bcos',
            'file_name'     => 'metadata.bcos',
            'status'        => ResultFile::STATUS_DONE,
        ]);

        DB::table('collection_metadata')->insert([
            'collection_id'  => $this->collection->id,
            'result_file_id' => $this->resultFile->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function seedDistributions(array $categories): void
    {
        $rows = [];
        foreach ($categories as $category) {
            $rows[] = [
                'collection_id'  => $this->collection->id,
                'task_id'        => $this->resultFile->task_id,
                'result_file_id' => $this->resultFile->id,
                'category'       => $category,
                'name'           => 'TEST_CODE',
                'description'    => 'Test',
                'concept_id'     => null,
                'count'          => 100,
                'created_at'     => now(),
                'updated_at'     => now(),
            ];
        }
        DB::table('distributions')->insert($rows);
    }

    public function test_it_sets_flags_for_present_domains(): void
    {
        $this->seedDistributions(['Condition', 'Drug', 'Observation', 'Measurement', 'DEMOGRAPHICS', 'Location', 'Death']);

        (new SyncCollectionCapabilities($this->resultFile->id))->handle();

        $meta = CollectionMetadata::where('result_file_id', $this->resultFile->id)->first();

        $this->assertSame(1, $meta->supports_condition);
        $this->assertSame(1, $meta->supports_drug);
        $this->assertSame(1, $meta->supports_observation);
        $this->assertSame(1, $meta->supports_measurement);
        $this->assertSame(1, $meta->supports_demographics);
        $this->assertSame(1, $meta->supports_location_filter);
        $this->assertSame(1, $meta->death_filter);
        $this->assertSame(1, $meta->supports_death_filter);
    }

    public function test_it_sets_flags_to_zero_for_absent_domains(): void
    {
        $this->seedDistributions(['Condition']);

        (new SyncCollectionCapabilities($this->resultFile->id))->handle();

        $meta = CollectionMetadata::where('result_file_id', $this->resultFile->id)->first();

        $this->assertSame(1, $meta->supports_condition);
        $this->assertSame(0, $meta->supports_drug);
        $this->assertSame(0, $meta->supports_observation);
        $this->assertSame(0, $meta->supports_measurement);
        $this->assertSame(0, $meta->supports_demographics);
        $this->assertSame(0, $meta->supports_location_filter);
        $this->assertSame(0, $meta->death_filter);
    }

    public function test_it_sets_supports_death_filter_from_concept_id_4306655(): void
    {
        // No Death category, but the death observation concept is present
        DB::table('distributions')->insert([
            'collection_id'  => $this->collection->id,
            'task_id'        => $this->resultFile->task_id,
            'result_file_id' => $this->resultFile->id,
            'category'       => 'Observation',
            'name'           => '4306655',
            'description'    => 'Death observation',
            'concept_id'     => 4306655,
            'count'          => 50,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        (new SyncCollectionCapabilities($this->resultFile->id))->handle();

        $meta = CollectionMetadata::where('result_file_id', $this->resultFile->id)->first();

        $this->assertSame(0, $meta->death_filter);
        $this->assertSame(1, $meta->supports_death_filter);
    }

    public function test_location_has_coordinates_is_not_touched(): void
    {
        DB::table('collection_metadata')
            ->where('result_file_id', $this->resultFile->id)
            ->update(['location_has_coordinates' => 1]);

        $this->seedDistributions(['Location']);

        (new SyncCollectionCapabilities($this->resultFile->id))->handle();

        $meta = CollectionMetadata::where('result_file_id', $this->resultFile->id)->first();
        $this->assertSame(1, $meta->location_has_coordinates);
    }
}
