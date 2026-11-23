# Integrated-mode claim sync

In integrated mode the HDR UK Gateway signs a JWT for each session, and `DecodeJwt` syncs the user's memberships from the `user` object in that token. The sync runs once per token (keyed on `jti`, cached until the token expires or for an hour, whichever is sooner).

## Claims read from the token

| Claim | Shape | Synced by |
|-------|-------|-----------|
| `workgroups` | array of workgroup names | `WorkgroupSyncerService` |
| `cohort_discovery_nhs_sde` | boolean | `WorkgroupSyncerService` |
| `cohort_discovery_roles` | array of role names | `RoleSyncerService` |
| `cohort_admin_teams` | array of `{ "id": <int or string>, "name": <string> }` | `CustodianSyncerService` |

An absent or `null` claim leaves that membership untouched. A present but empty array means "none" and clears the membership when the subject is authoritative.

## `cohort_admin_teams`

The Gateway sends each team's `id` as an integer:

```json
"cohort_admin_teams": [
  { "id": 21, "name": "Example Team" }
]
```

`CustodianSyncerService` accepts an integer or string `id` and stores it as a string in `custodians.external_custodian_id`, which is the upsert key. An integer `21` and a previously stored `"21"` therefore resolve to the same custodian. Entries with any other `id` type, or without a string `name`, are skipped and logged as `Custodian claim sync skipped malformed entries`. If every entry is skipped the user's custodians are left unchanged rather than cleared.

Before DP-1275 the syncer accepted string ids only, so every team from the Gateway was skipped and no custodian could be created or attached from the token.

## Configuration

Trust and authority per subject are set in `config/claimsaccesscontrol.php` under `sync`, via the `CLAIM_SYNC_*` environment variables. See `claims-access-control-editability.md` for how these modes surface to admins.
