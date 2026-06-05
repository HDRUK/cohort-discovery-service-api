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

        $response = $this->getJson(self::URL.'?q=AB1+0AA');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals('AB10AA', $data['postcode']);
        $this->assertEquals('S01013490', $data['lsoa_code']);
        $this->assertEquals('Cults, Bieldside and Milltimber West - 02', $data['lsoa_name']);
        $this->assertEquals('S12000033', $data['lad_code']);
        $this->assertEquals('Aberdeen City', $data['lad_name']);
        $this->assertEquals(57.1001, $data['latitude']);
        $this->assertEquals(-2.1802, $data['longitude']);
        $this->assertEquals('Scotland', $data['country']);
    }

    public function test_normalises_postcode_before_lookup(): void
    {
        DB::table('postcode_lookup')->insert([
            'postcode' => 'SW1A1AA',
            'lsoa21cd' => 'E01000001',
            'lsoa21nm' => 'City of London 001A',
            'ladcd'    => 'E09000001',
            'ladnm'    => 'City of London',
        ]);

        // Various input formats should all resolve
        foreach (['SW1A 1AA', 'sw1a 1aa', 'SW1A1AA'] as $input) {
            $this->getJson(self::URL.'?q='.urlencode($input))
                ->assertOk()
                ->assertJsonPath('data.postcode', 'SW1A1AA');
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
            DB::table('postcode_lookup')->insert([
                'postcode' => 'AA'.strtoupper(substr($ladcd, 0, 1)).'11AA',
                'lsoa21cd' => null,
                'lsoa21nm' => null,
                'ladcd'    => $ladcd,
                'ladnm'    => 'Test LAD',
            ]);

            $response = $this->getJson(self::URL.'?q=AA'.urlencode(strtoupper(substr($ladcd, 0, 1))).'11AA');
            $response->assertOk();
            $this->assertEquals($expectedCountry, $response->json('data.country'));

            DB::table('postcode_lookup')->truncate();
        }
    }

    public function test_returns_null_lat_lon_when_no_centroid_exists(): void
    {
        DB::table('postcode_lookup')->insert([
            'postcode' => 'ZE10AB',
            'lsoa21cd' => 'S99999999',
            'lsoa21nm' => 'Unknown Zone',
            'ladcd'    => 'S12000027',
            'ladnm'    => 'Shetland Islands',
        ]);

        $response = $this->getJson(self::URL.'?q=ZE1+0AB');

        $response->assertOk();
        $this->assertNull($response->json('data.latitude'));
        $this->assertNull($response->json('data.longitude'));
    }

    public function test_returns_404_for_unknown_postcode(): void
    {
        $this->getJson(self::URL.'?q=ZZ99+9ZZ')->assertNotFound();
    }

    public function test_returns_422_when_q_is_missing(): void
    {
        $this->getJson(self::URL)->assertUnprocessable();
    }

    public function test_returns_422_when_q_exceeds_max_length(): void
    {
        $this->getJson(self::URL.'?q=TOOLONGPOSTCODE')->assertUnprocessable();
    }

    public function test_rejects_unauthenticated_requests_when_basic_auth_enabled(): void
    {
        Config::set('system.basic_auth_enabled', true);
        $this->enableMiddleware();

        $this->get(self::URL.'?q=AB1+0AA')->assertUnauthorized();
    }
}
