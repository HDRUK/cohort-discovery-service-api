<?php

namespace Tests\Unit;

use App\Models\Workgroup;
use Hdruk\ClaimsAccessControl\Services\ClaimMappingService;
use Hdruk\ClaimsAccessControl\Services\ClaimResolverService;
use Tests\TestCase;

class CBACTest extends TestCase
{
    private ClaimMappingService $claimMappingService;

    private ClaimResolverService $claimResolverService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolveServices();
    }

    private function resolveServices(): void
    {
        $this->app->forgetInstance(ClaimMappingService::class);
        $this->app->forgetInstance(ClaimResolverService::class);

        $this->claimMappingService = app(ClaimMappingService::class);
        $this->claimResolverService = app(ClaimResolverService::class);
    }

    public function test_the_application_can_set_default_system_maps(): void
    {
        $this->assertNotEmpty($this->claimMappingService->getMap());
        $this->assertArrayHasKey(
            config('claims-access.default_system'),
            $this->claimMappingService->getMap()
        );

        $localWorkgroups = array_keys(config('claimsaccesscontrol.workgroup_mappings'));
        $curatedSystemMap = [
            config('claims-access.default_system') => array_combine($localWorkgroups, $localWorkgroups),
        ];

        $this->assertEquals(
            $curatedSystemMap,
            $this->claimMappingService->getMap()
        );
    }

    public function test_the_application_can_map_workgroups(): void
    {
        // Manually create a workgroup array to simulate valid claims
        $workgroups['workgroups'] = [
            config('claims-access.default_system') => [
                'admin',
                'uk-research',
            ],
        ];

        $this->assertTrue($this->claimResolverService->hasWorkgroup(
            $workgroups,
            config('claims-access.default_system'),
            'admin'
        ));

        // Manually create a workgroup array to simulate invalid claims
        $workgroups['workgroups'] = [
            config('claims-access.default_system') => [
                'doesntexist',
                'lowest',
            ],
        ];

        $this->assertFalse($this->claimResolverService->hasWorkgroup(
            $workgroups,
            config('claims-access.default_system'),
            'admin'
        ));
    }

    public function test_list_valued_mappings_still_resolve_local_workgroups(): void
    {
        config(['claimsaccesscontrol.workgroup_mappings' => [
            'uk-research' => ['ukri', 'ncs', 'breathe'],
            'uk-industry' => 'gsk',
            'non-uk-industry' => 'gsk',
        ]]);
        $this->resolveServices();

        $claims['workgroups'] = [
            config('claims-access.default_system') => ['uk-research'],
        ];

        $this->assertTrue($this->claimResolverService->hasWorkgroup(
            $claims,
            config('claims-access.default_system'),
            'uk-research'
        ));
    }

    public function test_workgroups_sharing_an_idp_value_do_not_satisfy_each_other(): void
    {
        config(['claimsaccesscontrol.workgroup_mappings' => [
            'uk-industry' => 'gsk',
            'non-uk-industry' => 'gsk',
        ]]);
        $this->resolveServices();

        $claims['workgroups'] = [
            config('claims-access.default_system') => ['uk-industry'],
        ];

        $this->assertFalse($this->claimResolverService->hasWorkgroup(
            $claims,
            config('claims-access.default_system'),
            'non-uk-industry'
        ));
    }
}
