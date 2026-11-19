<?php

namespace Tests\Unit\Claims;

use App\Models\Workgroup;
use App\Services\Claims\ClaimResolver;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClaimResolverTest extends TestCase
{
    private ClaimResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['UK-RESEARCH', 'UK-INDUSTRY', 'NON-UK-INDUSTRY', 'OTHER'] as $name) {
            Workgroup::updateOrCreate(['name' => $name], ['active' => 1]);
        }

        config(['claimsaccesscontrol.workgroup_mappings' => [
            'uk-research' => ['ukri', 'ncs', 'breathe'],
            'uk-industry' => 'gsk',
            'non-uk-industry' => 'gsk',
            'other' => 'other',
        ]]);

        $this->resolver = app(ClaimResolver::class);
    }

    private function workgroupIds(string ...$names): array
    {
        return Workgroup::whereIn('name', $names)->orderBy('id')->pluck('id')->all();
    }

    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    public function test_a_string_value_matches_case_insensitively(): void
    {
        $this->assertSame(
            $this->workgroupIds('OTHER'),
            $this->resolver->workgroupIdsForClaimValues(['OTHER'])
        );
    }

    public function test_one_idp_value_still_gives_every_workgroup_it_maps_to(): void
    {
        $this->assertSame(
            $this->workgroupIds('UK-INDUSTRY', 'NON-UK-INDUSTRY'),
            $this->sorted($this->resolver->workgroupIdsForClaimValues(['gsk']))
        );
    }

    public function test_any_value_in_a_list_gives_the_workgroup(): void
    {
        foreach (['ukri', 'NCS', 'breathe'] as $claimValue) {
            $this->assertSame(
                $this->workgroupIds('UK-RESEARCH'),
                $this->resolver->workgroupIdsForClaimValues([$claimValue]),
                $claimValue
            );
        }
    }

    public function test_several_values_from_one_list_give_the_workgroup_once(): void
    {
        $this->assertSame(
            $this->workgroupIds('UK-RESEARCH'),
            $this->resolver->workgroupIdsForClaimValues(['ukri', 'breathe'])
        );
    }

    public function test_a_list_with_none_of_its_values_claimed_gives_nothing(): void
    {
        $this->assertSame([], $this->resolver->workgroupIdsForClaimValues(['uk-research', 'bbmri']));
    }

    public function test_role_mappings_accept_lists_too(): void
    {
        config(['claimsaccesscontrol.role_mappings' => [
            'admin' => ['platform-admin', 'SYSTEM_ADMIN'],
            'user' => 'GENERAL_ACCESS',
        ]]);

        $adminId = (int) Role::where('name', 'admin')->value('id');

        $this->assertSame([$adminId], $this->resolver->roleIdsForClaimValues(['platform-admin']));
        $this->assertSame([$adminId], $this->resolver->roleIdsForClaimValues(['system_admin']));
        $this->assertSame([], $this->resolver->roleIdsForClaimValues(['researcher']));
    }
}
