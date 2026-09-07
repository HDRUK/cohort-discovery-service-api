<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\CustodianHasUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionHealthControllerTest extends TestCase
{
    private User $adminUser;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMiddleware();

        DB::table('collection_ping_buckets')->truncate();

        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('admin');

        $this->collection = Collection::factory()->bunny()->create();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function url(Collection $collection, array $query = []): string
    {
        $url = "/api/v1/collections/{$collection->pid}/health";

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private function bucket(Carbon $minute, TaskType $taskType, int $n): void
    {
        $start = $minute->copy()->startOfMinute();

        DB::table('collection_ping_buckets')->insert([
            'collection_id' => $this->collection->id,
            'task_type' => $taskType->value,
            'bucket_minute' => $start->toDateTimeString(),
            'n' => $n,
            'first_ping_at' => $start->toDateTimeString(),
            'last_ping_at' => $start->copy()->addSeconds(30)->toDateTimeString(),
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_defaults_to_sixty_zero_filled_minute_bins_for_both_task_types()
    {
        $this->bucket(Carbon::now(), TaskType::A, 3);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url($this->collection));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame('minute', $data['bin']);
        $this->assertCount(60, $data['series']['a']);
        $this->assertCount(60, $data['series']['b']);

        $this->assertSame(3, $data['series']['a'][59]['n']);
        $this->assertSame(0, $data['series']['a'][58]['n']);

        $this->assertSame(3, $data['summary']['a']['pings']);
        $this->assertSame(60, $data['summary']['a']['bins']);
        $this->assertSame(59, $data['summary']['a']['empty_bins']);
        $this->assertNotNull($data['summary']['a']['last_ping_at']);

        $this->assertSame(0, $data['summary']['b']['pings']);
        $this->assertSame(60, $data['summary']['b']['empty_bins']);
        $this->assertSame(60, $data['summary']['b']['longest_gap_bins']);
        $this->assertNull($data['summary']['b']['last_ping_at']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_sums_minute_buckets_into_hour_bins()
    {
        $now = Carbon::now();
        $thisHour = $now->copy()->startOfHour();

        $this->bucket($thisHour->copy()->addMinutes(2), TaskType::A, 5);
        $this->bucket($thisHour->copy()->addMinutes(7), TaskType::A, 7);
        $this->bucket($thisHour->copy()->subMinutes(10), TaskType::A, 4);

        $response = $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, ['bin' => 'hour', 'window' => '24h']));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame('hour', $data['bin']);
        $this->assertCount(24, $data['series']['a']);

        $this->assertSame(12, $data['series']['a'][23]['n']);
        $this->assertSame(4, $data['series']['a'][22]['n']);
        $this->assertSame(16, $data['summary']['a']['pings']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_reports_a_per_minute_rate_at_minute_resolution()
    {
        $this->bucket(Carbon::now(), TaskType::A, 3);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url($this->collection));

        $response->assertOk();
        $series = $response->json('data.series.a');

        $this->assertSame(1, $series[59]['minutes']);
        $this->assertEquals(3, $series[59]['per_minute']);
        $this->assertSame(0, $series[59]['silent_minutes']);

        $this->assertEquals(0, $series[58]['per_minute']);
        $this->assertSame(1, $series[58]['silent_minutes']);

        $this->assertSame(60, $response->json('data.summary.a.minutes'));
        $this->assertSame(0.05, $response->json('data.summary.a.per_minute'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_normalises_a_complete_hour_bin_to_a_per_minute_rate()
    {
        $hour = Carbon::now()->subHour()->startOfHour();

        $this->bucket($hour->copy()->addMinutes(2), TaskType::A, 5);
        $this->bucket($hour->copy()->addMinutes(7), TaskType::A, 7);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url($this->collection, [
            'bin' => 'hour',
            'from' => $hour->toIso8601ZuluString(),
            'to' => $hour->copy()->addMinutes(30)->toIso8601ZuluString(),
        ]));

        $response->assertOk();
        $point = $response->json('data.series.a.0');

        $this->assertSame(12, $point['n']);
        $this->assertSame(60, $point['minutes']);
        $this->assertSame(0.2, $point['per_minute']);
        $this->assertSame(58, $point['silent_minutes']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_reports_the_same_rate_at_every_bin_width()
    {
        $hour = Carbon::now()->subHour()->startOfHour();

        $this->bucket($hour->copy()->addMinutes(2), TaskType::A, 5);
        $this->bucket($hour->copy()->addMinutes(7), TaskType::A, 7);

        $expectedBins = ['minute' => 60, '10m' => 6, 'hour' => 1];

        foreach ($expectedBins as $bin => $bins) {
            $response = $this->actingAsJwt($this->adminUser)->getJson($this->url($this->collection, [
                'bin' => $bin,
                'from' => $hour->toIso8601ZuluString(),
                'to' => $hour->copy()->addMinutes(59)->toIso8601ZuluString(),
            ]));

            $response->assertOk();
            $summary = $response->json('data.summary.a');

            $this->assertCount($bins, $response->json('data.series.a'), $bin);
            $this->assertSame(12, $summary['pings'], $bin);
            $this->assertSame(60, $summary['minutes'], $bin);
            $this->assertSame(0.2, $summary['per_minute'], $bin);
            $this->assertSame(58, $summary['silent_minutes'], $bin);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_divides_the_in_progress_bin_by_the_minutes_elapsed_so_far()
    {
        Carbon::setTestNow('2026-09-02 10:05:30');

        foreach (range(0, 5) as $minute) {
            $this->bucket(Carbon::parse('2026-09-02 10:00:00')->addMinutes($minute), TaskType::A, 10);
        }

        $response = $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, ['bin' => 'hour', 'window' => '1h']));

        $response->assertOk();
        $point = $response->json('data.series.a.0');

        $this->assertSame(60, $point['n']);
        $this->assertSame(6, $point['minutes']);
        $this->assertEquals(10, $point['per_minute']);
        $this->assertSame(0, $point['silent_minutes']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_counts_silent_minutes_inside_a_wide_bin()
    {
        $slot = Carbon::now()->subHour()->startOfHour();

        foreach ([0, 3, 7] as $minute) {
            $this->bucket($slot->copy()->addMinutes($minute), TaskType::A, 2);
        }

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url($this->collection, [
            'bin' => '10m',
            'from' => $slot->toIso8601ZuluString(),
            'to' => $slot->copy()->addMinutes(5)->toIso8601ZuluString(),
        ]));

        $response->assertOk();
        $point = $response->json('data.series.a.0');

        $this->assertSame(6, $point['n']);
        $this->assertSame(10, $point['minutes']);
        $this->assertSame(0.6, $point['per_minute']);
        $this->assertSame(7, $point['silent_minutes']);
        $this->assertSame(0, $response->json('data.summary.a.empty_bins'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_reports_a_null_rate_for_bins_wholly_in_the_future()
    {
        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url($this->collection, [
            'bin' => 'hour',
            'from' => Carbon::now()->addHour()->toIso8601ZuluString(),
            'to' => Carbon::now()->addHours(2)->toIso8601ZuluString(),
        ]));

        $response->assertOk();

        foreach ($response->json('data.series.a') as $point) {
            $this->assertSame(0, $point['minutes']);
            $this->assertNull($point['per_minute']);
            $this->assertSame(0, $point['silent_minutes']);
        }

        $this->assertSame(0, $response->json('data.summary.a.minutes'));
        $this->assertNull($response->json('data.summary.a.per_minute'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_weights_the_summary_average_by_minutes_rather_than_by_bin()
    {
        Carbon::setTestNow('2026-09-02 10:05:30');

        foreach (range(0, 5) as $step) {
            $this->bucket(Carbon::parse('2026-09-02 09:00:00')->addMinutes($step * 10), TaskType::A, 10);
            $this->bucket(Carbon::parse('2026-09-02 10:00:00')->addMinutes($step), TaskType::A, 10);
        }

        $response = $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, ['bin' => 'hour', 'window' => '2h']));

        $response->assertOk();
        $series = $response->json('data.series.a');
        $summary = $response->json('data.summary.a');

        $this->assertEquals(1, $series[0]['per_minute']);
        $this->assertEquals(10, $series[1]['per_minute']);

        $this->assertSame(120, $summary['pings']);
        $this->assertSame(66, $summary['minutes']);
        $this->assertSame(1.818, $summary['per_minute']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_bins_at_a_custom_ten_minute_width()
    {
        $anchor = Carbon::now()->startOfHour();

        $this->bucket($anchor->copy()->addMinutes(2), TaskType::A, 3);
        $this->bucket($anchor->copy()->addMinutes(9), TaskType::A, 4);
        $this->bucket($anchor->copy()->addMinutes(11), TaskType::A, 5);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url($this->collection, [
            'bin' => '10m',
            'from' => $anchor->copy()->addMinutes(5)->toIso8601ZuluString(),
            'to' => $anchor->copy()->addMinutes(12)->toIso8601ZuluString(),
        ]));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame('10m', $data['bin']);
        $this->assertSame($anchor->toIso8601ZuluString(), $data['from']);
        $this->assertSame($anchor->copy()->addMinutes(20)->toIso8601ZuluString(), $data['to']);

        $series = $data['series']['a'];
        $this->assertCount(2, $series);
        $this->assertSame($anchor->toIso8601ZuluString(), $series[0]['bin']);
        $this->assertSame($anchor->copy()->addMinutes(10)->toIso8601ZuluString(), $series[1]['bin']);
        $this->assertSame(7, $series[0]['n']);
        $this->assertSame(5, $series[1]['n']);
        $this->assertSame(12, $data['summary']['a']['pings']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_fits_a_one_hour_window_into_six_ten_minute_bins()
    {
        $this->bucket(Carbon::now(), TaskType::A, 2);

        $response = $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, ['bin' => '10m', 'window' => '1h']));

        $response->assertOk();
        $series = $response->json('data.series.a');

        $this->assertCount(6, $series);
        $this->assertSame(2, $series[5]['n']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_normalises_a_multiple_of_one_to_the_named_unit()
    {
        $response = $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, ['bin' => '1h', 'window' => '24h']));

        $response->assertOk();
        $this->assertSame('hour', $response->json('data.bin'));
        $this->assertCount(24, $response->json('data.series.a'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_lines_up_sql_and_php_boundaries_for_multi_week_bins()
    {
        $this->bucket(Carbon::now(), TaskType::A, 6);

        $response = $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, ['bin' => '2w', 'window' => '8w']));

        $response->assertOk();
        $series = $response->json('data.series.a');

        $this->assertCount(4, $series);
        $this->assertSame(6, $response->json('data.summary.a.pings'));

        foreach ($series as $point) {
            $this->assertSame(
                \Carbon\CarbonInterface::MONDAY,
                Carbon::parse($point['bin'])->dayOfWeek
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_lines_up_sql_and_php_boundaries_for_week_bins()
    {
        $this->bucket(Carbon::now(), TaskType::A, 9);

        $response = $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, ['bin' => 'week', 'window' => '2w']));

        $response->assertOk();
        $series = $response->json('data.series.a');

        $this->assertCount(2, $series);
        $this->assertSame(9, $series[1]['n']);
        $this->assertSame(9, $response->json('data.summary.a.pings'));
        $this->assertSame(
            Carbon::now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->toIso8601ZuluString(),
            $series[1]['bin']
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_lines_up_sql_and_php_boundaries_for_month_bins()
    {
        $this->bucket(Carbon::now(), TaskType::A, 4);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url($this->collection, [
            'bin' => 'month',
            'from' => Carbon::now()->startOfMonth()->toIso8601ZuluString(),
            'to' => Carbon::now()->toIso8601ZuluString(),
        ]));

        $response->assertOk();
        $series = $response->json('data.series.a');

        $this->assertCount(1, $series);
        $this->assertSame(4, $series[0]['n']);
        $this->assertSame(
            Carbon::now()->startOfMonth()->toIso8601ZuluString(),
            $series[0]['bin']
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prefers_an_explicit_from_over_the_window()
    {
        $to = Carbon::now()->startOfHour();
        $from = $to->copy()->subHours(2);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url($this->collection, [
            'bin' => 'hour',
            'window' => '24h',
            'from' => $from->toIso8601ZuluString(),
            'to' => $to->toIso8601ZuluString(),
        ]));

        $response->assertOk();

        $this->assertCount(3, $response->json('data.series.a'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_reports_the_longest_interior_gap()
    {
        $now = Carbon::now()->startOfMinute();

        foreach ([6, 5, 1, 0] as $minutesAgo) {
            $this->bucket($now->copy()->subMinutes($minutesAgo), TaskType::A, 2);
        }

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url($this->collection, [
            'bin' => 'minute',
            'from' => $now->copy()->subMinutes(6)->toIso8601ZuluString(),
            'to' => $now->toIso8601ZuluString(),
        ]));

        $response->assertOk();
        $summary = $response->json('data.summary.a');

        $this->assertSame(7, $summary['bins']);
        $this->assertSame(8, $summary['pings']);
        $this->assertSame(3, $summary['empty_bins']);
        $this->assertSame(3, $summary['longest_gap_bins']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_an_all_zero_series_for_a_collection_that_has_never_been_polled()
    {
        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url($this->collection));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(60, $data['series']['a']);
        $this->assertSame(0, $data['summary']['a']['pings']);
        $this->assertSame(60, $data['summary']['a']['longest_gap_bins']);
        $this->assertNull($data['summary']['a']['last_ping_at']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_resolves_a_collection_by_numeric_id()
    {
        $response = $this->actingAsJwt($this->adminUser)
            ->getJson("/api/v1/collections/{$this->collection->id}/health");

        $response->assertOk();
        $this->assertSame($this->collection->id, $response->json('data.collection_id'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_an_unknown_bin()
    {
        $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, ['bin' => 'fortnight']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bin');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_bin_widths_it_cannot_express_in_minutes()
    {
        foreach (['2mo', '0m', '-5m', '90s', 'm'] as $bin) {
            $this->actingAsJwt($this->adminUser)
                ->getJson($this->url($this->collection, ['bin' => $bin]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('bin');
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_a_custom_bin_width_needing_more_bins_than_the_ceiling_allows()
    {
        $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, ['bin' => '10m', 'window' => '90d']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bin');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_a_malformed_window()
    {
        $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, ['window' => 'soon']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('window');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_a_from_later_than_to()
    {
        $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, [
                'from' => Carbon::now()->toIso8601ZuluString(),
                'to' => Carbon::now()->subDay()->toIso8601ZuluString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_a_range_needing_more_bins_than_the_ceiling_allows()
    {
        $this->actingAsJwt($this->adminUser)
            ->getJson($this->url($this->collection, ['bin' => 'minute', 'window' => '90d']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bin');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_not_found_for_an_unknown_collection()
    {
        $this->actingAsJwt($this->adminUser)
            ->getJson('/api/v1/collections/'.Str::uuid().'/health')
            ->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_forbids_a_user_unconnected_to_the_collections_custodian()
    {
        $outsider = User::factory()->create();

        $this->actingAsJwt($outsider)
            ->getJson($this->url($this->collection))
            ->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_a_user_attached_to_the_collections_custodian()
    {
        $custodianUser = User::factory()->create();
        CustodianHasUser::create([
            'custodian_id' => $this->collection->custodian_id,
            'user_id' => $custodianUser->id,
        ]);

        $this->actingAsJwt($custodianUser)
            ->getJson($this->url($this->collection))
            ->assertOk();
    }
}
