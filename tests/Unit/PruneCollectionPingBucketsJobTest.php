<?php

namespace Tests\Unit;

use App\Enums\TaskType;
use App\Jobs\PruneCollectionPingBucketsJob;
use App\Models\Collection;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneCollectionPingBucketsJobTest extends TestCase
{
    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('collection_ping_buckets')->truncate();

        $this->collection = Collection::factory()->bunny()->create();
    }

    private function bucket(Carbon $minute): void
    {
        DB::table('collection_ping_buckets')->insert([
            'collection_id' => $this->collection->id,
            'task_type' => TaskType::A->value,
            'bucket_minute' => $minute->copy()->startOfMinute()->toDateTimeString(),
            'n' => 1,
            'first_ping_at' => $minute->toDateTimeString(),
            'last_ping_at' => $minute->toDateTimeString(),
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_deletes_buckets_past_the_retention_window_and_keeps_recent_ones(): void
    {
        config()->set('system.collection_ping_retention_days', 30);

        $now = Carbon::now();
        $this->bucket($now->copy()->subDays(31));
        $this->bucket($now->copy()->subDays(45));
        $this->bucket($now->copy()->subDays(29));
        $this->bucket($now);

        (new PruneCollectionPingBucketsJob())->handle();

        $remaining = DB::table('collection_ping_buckets')
            ->where('collection_id', $this->collection->id)
            ->orderBy('bucket_minute')
            ->pluck('bucket_minute');

        $this->assertCount(2, $remaining);
        $this->assertSame(
            $now->copy()->subDays(29)->startOfMinute()->toDateTimeString(),
            Carbon::parse($remaining[0])->toDateTimeString()
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_honours_a_shorter_retention_window(): void
    {
        config()->set('system.collection_ping_retention_days', 1);

        $now = Carbon::now();
        $this->bucket($now->copy()->subDays(2));
        $this->bucket($now);

        (new PruneCollectionPingBucketsJob())->handle();

        $this->assertSame(
            1,
            DB::table('collection_ping_buckets')->where('collection_id', $this->collection->id)->count()
        );
    }
}
