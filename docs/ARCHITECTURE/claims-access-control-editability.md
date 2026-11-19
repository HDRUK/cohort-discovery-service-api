# Claims access control: membership editability

Some memberships can be written by an identity provider (IdP), or by the HDR UK Gateway in integrated mode, as well as by admins. This applies to a user's workgroups, roles and custodians. The API tells clients which of those memberships are managed that way, so an admin isn't offered an edit that the next sign-in will undo.

## Where claims reach a user

Claim sync only touches a user through one of two paths:

| Path | Code | When |
|---|---|---|
| Standalone SSO | `SsoUserResolver` → `ClaimMapper::apply` | Sign-in at an enabled OIDC provider. Only subjects that provider's `claim_mapping` maps are synced. |
| Integrated | `DecodeJwt::syncIntegratedOncePerToken` → `TokenSync/*SyncerService` | Once per Gateway token (`jti`). |

Password sign-ins never sync. So on one deployment, a password-only user can be fully editable while an SSO-linked user is read-only.

Both paths use `ClaimSyncPolicy`. Trust (the `ClaimTrust` enum: `never`, `first_login`, `always`) and `authoritative` are set per subject in `config/claimsaccesscontrol.php`. The claim mapping is set per provider in `config/sso.php`. `users.claims_synced_at` is a single timestamp per user, shared by all subjects.

An unrecognised trust value refuses to boot. `ClaimsAccessControlServiceProvider` checks every subject at registration, the same way `ApplicationModeServiceProvider` checks `APP_OPERATION_MODE`, so a typo can't quietly turn a sync off.

The boolean flags are checked at boot too. They are `authoritative`, `ensure_default`, `sde_from_claim` and `provision.defaults_on_create` (the provider's `FLAGS` map lists each one's env var).

- Accepted values: `true`, `"true"`, `1`, `"1"`, `false`, `"false"`, `0` and `"0"`.
- Anything else refuses to boot, including a missing key or a typo like `flase`. A plain `(bool)` cast used to read `flase` as true, which for `sde_from_claim` granted SDE workgroups.
- `ClaimSyncPolicy` reads each flag with `FILTER_VALIDATE_BOOLEAN`, so `"false"` and `"0"` read as false.

## Modes

`MembershipSources` (`app/Services/Claims/MembershipSources.php`) gives each subject one of these modes, defined as the `App\Enums\MembershipMode` backed enum. The enum also owns the editable rule:

| Mode | Rule | Editable |
|---|---|---|
| `internal` | No claim source, or trust is `never` | yes |
| `first_login` | Trust is `first_login`. Per user: only until `claims_synced_at` is set, then `internal` | yes |
| `additive` | Trust is `always`, not authoritative (sync uses `syncWithoutDetaching`) | yes, but removing an entry the IdP asserts won't last |
| `authoritative` | Trust is `always` and authoritative (sync uses `sync`) | no |

**Is there a claim source?**
- **Integrated mode:** always (`gateway`).
- **Standalone:** only when `LoginMethods::ssoAvailable()`, and only for providers that map the subject.
  - Workgroups are always mapped, because `ClaimMappingConfig` defaults the claim to `eduperson_entitlement`.
  - Roles and custodians need `roles_claim` or `custodians_claim`.
- **Per user (standalone):** only the user's `identities` at those providers count.

## Endpoints

- **`GET /api/v1/access_policy`** (`AccessPolicyController`) returns `MembershipSources::deployment()`. It is admin-only, through the `viewAny` user policy, because only the admin screens need it:
  - `operation_mode`;
  - `providers` (slug → label, or `gateway`);
  - for each subject: `mode`, `authoritative`, `editable` and `restored_on_sync`.
  - `authoritative` is only ever true for a subject that actually syncs.
- **`membership_sources`** on users from `GET /api/v1/users` and `GET /api/v1/users/{id}` (`MembershipSources::forUser()`):
  - for each subject: `mode`, `editable` and `sources`;
  - it reads the eager-loaded `identities` relation, so the index adds no per-user queries;
  - it is the `membership_sources` accessor on `User`, kept out of `$appends`. `UserController` opts in with `->append('membership_sources')`, so other user payloads (`/v1/user`, nested users) are unchanged.
- **`identities`** on the same two endpoints: `GET /api/v1/users/{id}` now eager-loads it too, matching the index.
  - Each entry has `id`, `user_id`, `provider`, `last_login_at` and timestamps.
  - `provider_sub` and `email_at_link` are hidden by `UserIdentity::$hidden`.

**Who can see this.** Both user endpoints are admin-only, through the `viewAny`/`view` user policy. A non-admin gets 403, even for their own id. `/v1/user`, the caller's own payload, carries neither `identities` nor `membership_sources`. `AccessPolicyTest` pins all of this.

`MembershipSources` is bound `scoped`, so the users index shares one instance (and its per-request provider map) across every user.

## Entries restored on every sync

`restored_on_sync` lists the entries a sync re-applies whatever the claims say. It is only populated for subjects that sync.

| Subject | Entry | `when` | Source |
|---|---|---|---|
| workgroups | `DEFAULT` | `every_sync` | `claimsaccesscontrol.sync.workgroups.ensure_default` |
| workgroups | `config('workgroups.sde_approved')` | `sde_approved` | Integrated mode with `sync.workgroups.sde_from_claim`. Approval comes from the Gateway token's `cohort_discovery_nhs_sde`, which is not stored, so it can't be resolved per user. |
| roles | `RoleSyncFloor::DEFAULT_ROLE` (`user`) | `every_sync` | `claimsaccesscontrol.sync.roles.ensure_default` |

`RoleSyncFloor` also keeps the last admin's `admin` role. It only ever keeps that role and never re-adds it, so it isn't listed.

The SDE workgroup list lives in `config/workgroups.php` under `sde_approved`, next to `seed`. A deployment can mount its own `workgroups.php` without that key, or with it set to `null`. In that case `Workgroup::sdeApprovedNames()` falls back to `Workgroup::SDE_APPROVED_NAMES` (`nhs-sde`, `uk-industry`, `uk-research`), so SDE sync doesn't quietly turn off.

Names are matched through `ClaimResolver::normalise()`, the same as sync. A non-string or empty entry is skipped instead of breaking `/access_policy`.

## Enforcement and audit

The policy is advisory: workgroup add and remove still succeed for managed users.
- Removing access must stay possible: it takes effect immediately and lasts until the user's next sign-in.
- The per-user answer is a prediction, because an IdP can leave a claim out and then nothing syncs.

When an admin adds or removes a workgroup for a user whose workgroups aren't `internal`, `UserController` writes an extra activity-log entry. It has `log_name` `users` and event `claim_managed_membership_edited`, with these properties:
- `relation`
- `action` (`attached`/`detached`)
- `workgroup.id`
- `mode`
- `sources`
- `authoritative`
- `restored_on_sync`, always `false` for `attached`. For `detached`:
  - `true`: the next sync puts the workgroup back (`every_sync`, e.g. `DEFAULT`);
  - `null`: unknown. This is an SDE workgroup (`sde_approved`). It comes back only if the user's Gateway token still carries SDE approval, which isn't stored;
  - `false`: nothing restores it.

Only workgroup membership has edit endpoints. For roles and custodians the policy is display-only.

## Tests

- `tests/Unit/Claims/MembershipSourcesTest.php`: mode resolution, mixed providers, disabled providers, `first_login` before and after sync, integrated mode, `restored_on_sync`, an unknown result for SDE removals, the `sde_approved` fallback and malformed names.
- `tests/Unit/Claims/ClaimSyncPolicyTest.php`: trust and boolean-flag validation at boot, and string/numeric flag parsing.
- `tests/Feature/AccessPolicyTest.php`: the endpoint, the per-user block on users, query count on the index, the activity-log warning, and that non-admins can't see `identities` or `membership_sources`.
