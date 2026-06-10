<?php

namespace Tests\Feature;

use Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostcodeLookupTest extends TestCase
{
    private const URL = '/api/v1/lookup/postcode';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('postcode_lookup')->truncate();
        DB::table('lsoa_centroids')->truncate();
    }

    public function test_returns_postcode_data_with_centroid(): void
    {
        DB::table('postcode_lookup')->insert([
            'postcode' => 'AB10AA',
            'lsoa21cd' => 'S01013490',
            'lsoa21nm' => 'Cults, Bieldside and Milltimber West - 02',
            'ladcd'    => 'S12000033',
            'ladnm'    => 'Aberdeen City',
        ]);

        DB::table('lsoa_centroids')->insert([
            'lsoa_code' => 'S01013490',
            'latitude'  => 57.1001,
            'longitude' => -2.1802,
        ]);

        $response = $this->postJson(self::URL, ['postcodes' => ['AB1 0AA']]);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('AB10AA', $data[0]['postcode']);
        $this->assertEquals('S01013490', $data[0]['lsoa_code']);
        $this->assertEquals('Cults, Bieldside and Milltimber West - 02', $data[0]['lsoa_name']);
        $this->assertEquals('S12000033', $data[0]['lad_code']);
        $this->assertEquals('Aberdeen City', $data[0]['lad_name']);
        $this->assertEquals(57.1001, $data[0]['latitude']);
        $this->assertEquals(-2.1802, $data[0]['longitude']);
        $this->assertEquals('Scotland', $data[0]['country']);
    }

    public function test_returns_multiple_postcodes(): void
    {
        DB::table('postcode_lookup')->insert([
            ['postcode' => 'AB10AA', 'lsoa21cd' => 'S01013490', 'lsoa21nm' => null, 'ladcd' => 'S12000033', 'ladnm' => 'Aberdeen City'],
            ['postcode' => 'SW1A1AA', 'lsoa21cd' => 'E01000001', 'lsoa21nm' => null, 'ladcd' => 'E09000001', 'ladnm' => 'City of London'],
        ]);

        $response = $this->postJson(self::URL, ['postcodes' => ['AB1 0AA', 'SW1A 1AA']]);

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_deduplicates_postcodes_before_querying(): void
    {
        DB::table('postcode_lookup')->insert([
            'postcode' => 'AB10AA', 'lsoa21cd' => null, 'lsoa21nm' => null, 'ladcd' => 'S12000033', 'ladnm' => null,
        ]);

        $response = $this->postJson(self::URL, ['postcodes' => ['AB1 0AA', 'AB10AA', 'ab1 0aa']]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_normalises_postcode_before_lookup(): void
    {
        DB::table('postcode_lookup')->insert([
            'postcode' => 'SW1A1AA', 'lsoa21cd' => null, 'lsoa21nm' => null, 'ladcd' => 'E09000001', 'ladnm' => null,
        ]);

        foreach (['SW1A 1AA', 'sw1a 1aa', 'SW1A1AA'] as $input) {
            $response = $this->postJson(self::URL, ['postcodes' => [$input]]);
            $response->assertOk();
            $this->assertEquals('SW1A1AA', $response->json('data.0.postcode'));
        }
    }

    public function test_derives_country_from_lad_code(): void
    {
        $cases = [
            ['E09000001', 'England'],
            ['W06000015', 'Wales'],
            ['S12000033', 'Scotland'],
            ['N09000001', 'Northern Ireland'],
        ];

        foreach ($cases as [$ladcd, $expectedCountry]) {
            $postcode = strtoupper(substr($ladcd, 0, 1)).'11AA';
            DB::table('postcode_lookup')->insert([
                'postcode' => $postcode, 'lsoa21cd' => null, 'lsoa21nm' => null, 'ladcd' => $ladcd, 'ladnm' => null,
            ]);

            $response = $this->postJson(self::URL, ['postcodes' => [$postcode]]);
            $response->assertOk();
            $this->assertEquals($expectedCountry, $response->json('data.0.country'));

            DB::table('postcode_lookup')->truncate();
        }
    }

    public function test_returns_null_lat_lon_when_no_centroid_exists(): void
    {
        DB::table('postcode_lookup')->insert([
            'postcode' => 'ZE10AB', 'lsoa21cd' => 'S99999999', 'lsoa21nm' => null, 'ladcd' => 'S12000027', 'ladnm' => null,
        ]);

        $response = $this->postJson(self::URL, ['postcodes' => ['ZE1 0AB']]);

        $response->assertOk();
        $this->assertNull($response->json('data.0.latitude'));
        $this->assertNull($response->json('data.0.longitude'));
    }

    public function test_returns_empty_array_for_unknown_postcode(): void
    {
        $response = $this->postJson(self::URL, ['postcodes' => ['ZZ99 9ZZ']]);

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_returns_empty_array_when_all_postcodes_unknown(): void
    {
        $response = $this->postJson(self::URL, ['postcodes' => ['ZZ99 9ZZ', 'XX00 0XX']]);

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    public function test_returns_422_when_postcodes_key_is_missing(): void
    {
        $this->postJson(self::URL, [])->assertUnprocessable();
    }

    public function test_returns_422_when_postcodes_is_empty_array(): void
    {
        $this->postJson(self::URL, ['postcodes' => []])->assertUnprocessable();
    }

    public function test_returns_422_when_batch_exceeds_1000(): void
    {
        $this->postJson(self::URL, ['postcodes' => array_fill(0, 1001, 'AB1 0AA')])->assertUnprocessable();
    }

    public function test_returns_422_when_a_postcode_exceeds_max_length(): void
    {
        $this->postJson(self::URL, ['postcodes' => ['TOOLONGPOSTCODE']])->assertUnprocessable();
    }

    public function test_rejects_unauthenticated_requests_when_basic_auth_enabled(): void
    {
        Config::set('system.basic_auth_enabled', true);
        $this->enableMiddleware();

        $this->postJson(self::URL, ['postcodes' => ['AB1 0AA']])->assertUnauthorized();
    }
}
