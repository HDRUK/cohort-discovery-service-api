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

`CustodianSyncerService` accepts an integer or string `id` and stores it as a string in `custodians.external_custodian_id`, which is the upsert key. An integer `21` and a previously stored `"21"` therefore resolve to the same custodian.

`external_custodian_id` holds Gateway team ids only. `admin-user:import` and `user:import-csv` create custodians with it unset and never overwrite it; they used to write the custodian's own local id there, which a Gateway team with the same numeric id would then match. Migration `2026_11_23_100000_clear_local_ids_from_custodians_external_custodian_id` clears `external_custodian_id` and `external_custodian_name` on rows they wrote before this change, where the id equals the row's own `id`, and logs each row it clears. A genuine Gateway custodian whose team id happens to equal its local id is cleared too, and is linked again by name on the next sign-in of a member of that team.

`custodians.name` is also unique. Before upserting, the syncer links a custodian with the team's name to the team only when that custodian has no `external_custodian_id` yet, so a custodian created by an import is linked to the Gateway team of the same name the first time a member of that team signs in. A team that is already linked to a custodian is never linked by name to a second one, which would break the unique index on `external_custodian_id`. A custodian already linked to one team is never re-pointed to another team with the same name: the second team is left unlinked, its members are not attached, and a warning is logged with its id. Entries with any other `id` type, or without a string `name`, are rejected. Each rejection writes a `claims_rejected` entry to the `claims` activity log, with the rejected entries and the claim's total, against the user. It also writes to the application log: an `error` when every team was rejected, because the user's custodians are then left unchanged rather than synced, and a `warning` when only some were. Rejection never throws, because `DecodeJwt` turns any exception into a `401` and would lock the user out over one bad entry.

Before DP-1275 the syncer accepted string ids only, so every team from the Gateway was skipped and no custodian could be created or attached from the token.

## Configuration

Trust and authority per subject are set in `config/claimsaccesscontrol.php` under `sync`, via the `CLAIM_SYNC_*` environment variables. See `claims-access-control-editability.md` for how these modes surface to admins.
