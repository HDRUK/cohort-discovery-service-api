<?php

namespace App\Services\Claims;

use App\Models\User;
use App\Models\Workgroup;
use App\Services\Authentication\LoginMethods;
use App\Services\Sso\ClaimMappingConfig;
use App\Services\Sso\OidcProviderConfig;
use App\Support\ApplicationMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class MembershipSources
{
    public const GATEWAY = 'gateway';

    public const GATEWAY_LABEL = 'HDR UK Gateway';

    public const RESTORED_EVERY_SYNC = 'every_sync';

    public const RESTORED_SDE_APPROVED = 'sde_approved';

    private const DEFAULT_WORKGROUP = 'DEFAULT';

    private ?array $subjectProviders = null;

    public function __construct(
        private readonly ClaimSyncPolicy $policy,
        private readonly LoginMethods $loginMethods,
    ) {
    }

    public function deployment(): array
    {
        $deployment = [
            'operation_mode' => ApplicationMode::current(),
            'providers' => (object) $this->providerLabels(),
        ];

        foreach (ClaimSyncPolicy::SUBJECTS as $subject) {
            $mode = $this->deploymentMode($subject);
            $syncs = $mode !== ClaimSyncPolicy::MODE_INTERNAL;

            $deployment[$subject] = [
                'mode' => $mode,
                'authoritative' => $syncs && $this->policy->isAuthoritative($subject),
                'editable' => $this->isEditable($mode),
                'restored_on_sync' => $syncs ? $this->restoredOnSync($subject) : [],
            ];
        }

        return $deployment;
    }

    public function forUser(User $user): array
    {
        $linkedProviders = $user->identities->pluck('provider')->unique()->all();

        $sources = [];

        foreach (ClaimSyncPolicy::SUBJECTS as $subject) {
            $sources[$subject] = $this->userSubject($user, $subject, $linkedProviders);
        }

        return $sources;
    }

    public function workgroupEditWarning(User $user, Workgroup $workgroup, string $action): ?array
    {
        $workgroups = $this->forUser($user)[ClaimSyncPolicy::SUBJECT_WORKGROUPS];

        if ($workgroups['mode'] === ClaimSyncPolicy::MODE_INTERNAL) {
            return null;
        }

        $restoredIds = collect($this->restoredOnSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS))
            ->where('when', self::RESTORED_EVERY_SYNC)
            ->pluck('id');

        return [
            'relation' => ClaimSyncPolicy::SUBJECT_WORKGROUPS,
            'action' => $action,
            'workgroup' => ['id' => $workgroup->id],
            'mode' => $workgroups['mode'],
            'sources' => $workgroups['sources'],
            'authoritative' => $this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_WORKGROUPS),
            'restored_on_sync' => $action === 'detached' && $restoredIds->contains($workgroup->id),
        ];
    }

    public function restoredOnSync(string $subject): array
    {
        return match ($subject) {
            ClaimSyncPolicy::SUBJECT_WORKGROUPS => $this->restoredWorkgroups(),
            ClaimSyncPolicy::SUBJECT_ROLES => $this->policy->shouldEnsureDefaultRole()
                ? $this->named(Role::query(), [RoleSyncFloor::DEFAULT_ROLE], self::RESTORED_EVERY_SYNC)
                : [],
            default => [],
        };
    }

    private function userSubject(User $user, string $subject, array $linkedProviders): array
    {
        $mode = $this->deploymentMode($subject);

        $sources = ApplicationMode::isIntegrated()
            ? $this->subjectProviders($subject)
            : array_values(array_intersect($this->subjectProviders($subject), $linkedProviders));

        $alreadySynced = $mode === ClaimSyncPolicy::MODE_FIRST_LOGIN && $user->claimsSyncedAt() !== null;

        if ($sources === [] || $alreadySynced) {
            $mode = ClaimSyncPolicy::MODE_INTERNAL;
        }

        return [
            'mode' => $mode,
            'editable' => $this->isEditable($mode),
            'sources' => $mode === ClaimSyncPolicy::MODE_INTERNAL ? [] : $sources,
        ];
    }

    private function deploymentMode(string $subject): string
    {
        if ($this->subjectProviders($subject) === []) {
            return ClaimSyncPolicy::MODE_INTERNAL;
        }

        return $this->policy->editingMode($subject);
    }

    private function isEditable(string $mode): bool
    {
        return $mode !== ClaimSyncPolicy::MODE_AUTHORITATIVE;
    }

    private function providerLabels(): array
    {
        if (ApplicationMode::isIntegrated()) {
            return [self::GATEWAY => self::GATEWAY_LABEL];
        }

        return $this->loginMethods->ssoAvailable() ? OidcProviderConfig::enabledProviders() : [];
    }

    private function subjectProviders(string $subject): array
    {
        $this->subjectProviders ??= $this->mapSubjectProviders();

        return $this->subjectProviders[$subject];
    }

    private function mapSubjectProviders(): array
    {
        if (ApplicationMode::isIntegrated()) {
            return array_fill_keys(ClaimSyncPolicy::SUBJECTS, [self::GATEWAY]);
        }

        $map = array_fill_keys(ClaimSyncPolicy::SUBJECTS, []);

        if (! $this->loginMethods->ssoAvailable()) {
            return $map;
        }

        foreach (array_keys(OidcProviderConfig::enabledProviders()) as $slug) {
            $slug = (string) $slug;
            $mapping = ClaimMappingConfig::fromArray(config("sso.providers.{$slug}.claim_mapping", []));

            $map[ClaimSyncPolicy::SUBJECT_WORKGROUPS][] = $slug;

            if ($mapping->rolesClaim) {
                $map[ClaimSyncPolicy::SUBJECT_ROLES][] = $slug;
            }

            if ($mapping->custodiansClaim) {
                $map[ClaimSyncPolicy::SUBJECT_CUSTODIANS][] = $slug;
            }
        }

        return $map;
    }

    private function restoredWorkgroups(): array
    {
        $restored = $this->policy->shouldEnsureDefaultWorkgroup()
            ? $this->named(Workgroup::query(), [self::DEFAULT_WORKGROUP], self::RESTORED_EVERY_SYNC)
            : [];

        if (ApplicationMode::isIntegrated() && $this->policy->shouldSyncSdeWorkgroupsFromClaim()) {
            $restored = array_merge(
                $restored,
                $this->named(Workgroup::query(), config('workgroups.sde_approved', []), self::RESTORED_SDE_APPROVED)
            );
        }

        return $restored;
    }

    private function named(Builder $query, array $names, string $when): array
    {
        return $query
            ->whereIn(DB::raw('LOWER(name)'), array_map('mb_strtolower', $names))
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($model) => ['id' => (int) $model->id, 'name' => $model->name, 'when' => $when])
            ->all();
    }
}
