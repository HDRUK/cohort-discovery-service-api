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

        $this->claimMappingService = new ClaimMappingService();
        $this->claimMappingService->setMap(config('claimsaccesscontrol.workgroup_mappings'));

        $this->claimResolverService = new ClaimResolverService($this->claimMappingService);
    }

    public function test_the_application_can_set_default_system_maps(): void
    {
        $this->claimMappingService->setMap(config('claimsaccesscontrol.workgroup_mappings'));

        $this->assertNotEmpty($this->claimMappingService->getMap());
        $this->assertArrayHasKey(
            config('claims-access.default_system'),
            $this->claimMappingService->getMap()
        );

        $curatedSystemMap = [
            config('claims-access.default_system') => config('claimsaccesscontrol.workgroup_mappings'),
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
                'cohort-admin',
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
}
