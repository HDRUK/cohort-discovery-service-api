# Scoped feature flags

Any feature flag that isn't [global-only](#global-only-flags) can be switched on or off for a single workgroup or a single user, as well as globally. For example, location and death queries can be enabled only for members of one workgroup, while every other user keeps the global setting.

## How a flag resolves for a user

`App\Services\Features\FeatureResolver` works it out in this order:

1. **User override:** if the user has one, use it, whether on or off.
2. **Workgroup overrides:** if any of the user's workgroups has the flag **on**, it is on. Otherwise, if any has it **off**, it is off. "On" wins when a user's workgroups disagree.
3. **Global value:** used when there are no overrides.

Clearing an override deletes the row, so the user or workgroup falls back to the next level down. `workgroups.active` is not taken into account, which matches how it is treated elsewhere.

With no user (queued jobs, console commands, BUNNY polling) only the global value applies.

## Global-only flags

`config/features.php` lists every flag and its default under `flags`, which `FeatureSeeder` seeds from. Flags listed under `global_only` can't be overridden:

| Flag | Why |
|---|---|
| `click-tracking-anonymous` | An override set to off would log one user's or one workgroup's clicks with their user id while everyone else stays anonymous. That is targeted tracking of individuals. |
| `flatten-nested-groups` | Read in translate and in BUNNY polling, which must agree. |
| `collection-task-backlog-limit` | Read in BUNNY polling, the backlog monitor, distributions and query submission. |
| `distribution-use-central-domain` | Read only in a queued job. |

For these flags:
- `FeatureResolver` returns the global value and ignores any override rows.
- `PUT /v1/features/{name}/overrides` returns 422.
- The overrides listing returns `overridable: false`.
- `FeatureSeeder` deletes any override rows they have, so stale overrides can't come back if a flag is later made overridable.

Every flag is overridable unless it is listed. Add a flag to `global_only` if an override would track individuals, or if the flag is read anywhere without a user. The values are literals rather than `env()`, so a deployment can't switch this off.

## Where overrides take effect

Overrides only change behaviour where the code asks `FeatureResolver` with a user. `GET /api/v1/features` does, so the web app always sees the user's effective value for every flag.

In the API:

| Flag | Read in | Honours overrides |
|---|---|---|
| `query-builder-use-location`, `query-builder-use-death` | `QuerySubmissionService` | Yes |
| `query-rate-limit` | `QueryRateLimiter` | Yes |
| `click-tracking-anonymous` | `ClickController@store` | No, global-only |
| `query-builder-use-collections-in-search`, `query-builder-use-stats-in-ordering` | `OmopController@searchConcepts`, `NLPConceptExtractor` | Yes |
| `query-builder-use-demographic-rule` | `RuleBuilderService` | Yes |
| `flatten-nested-groups` | `QueryController@translate`, `TaskController@nextJob` | No, global-only |
| `collection-task-backlog-limit` | `CollectionBacklogLimiter` (BUNNY polling, the backlog monitor, distributions and query submission) | No, global-only |
| `distribution-use-central-domain` | `RefreshLatestDistributionsView` job | No, global-only |

The last three are read where there is no user. Honouring an override in only some of the places they are read would make those places disagree. For example, a user's translate preview would differ from what BUNNY runs.

Every other flag is only read by the web app.

## Storage

Overrides are ordinary Pennant rows in the existing `features` table:

| Level | `scope` |
|---|---|
| Global | `__laravel_null` |
| User | `App\Models\User\|{id}` |
| Workgroup | `App\Models\Workgroup\|{id}` |

Writes go through Pennant: `Feature::for($subject)->activate()`, `->deactivate()` and `->forget()`. `AppServiceProvider` still pins the default scope to null. Every `Feature::active()` call therefore keeps resolving globally, and only code that goes through `FeatureResolver` sees overrides.

### Rules that keep overrides intact

- **Read user-facing flags through `FeatureResolver`, never `Feature::for($user)->value()`.** If a flag has no `Feature::define` resolver, Pennant returns `false` when there is no row, so "no override" and "override off" look the same. Defining a resolver doesn't fix it, because Pennant would then store the fallback value and freeze it for that user. `FeatureResolver` reads the table directly instead.
- **Never use `activateForEveryone` / `deactivateForEveryone` in application code.** They run `UPDATE features SET value = ? WHERE name = ?`, which overwrites every override. `PUT /v1/features/{name}` writes only the global row, with `Feature::for(null)->activate()`.
- **Pennant must use the `database` store.** The resolver reads the `features` table directly.
- **Model morph-map serialisation must stay off.** The overrides listing finds users and workgroups by the `App\Models\User|` and `App\Models\Workgroup|` scope prefixes. Turning on Pennant's `useMorphMap()` would change those prefixes.

### Clean-up

- **Deleted users and workgroups:** the `HasFeatureOverrides` trait deletes their rows from a `deleted` model hook, because `scope` is a string with no foreign key.
- **Retired flags:** `FeatureSeeder` purges a retired flag across every scope, so its overrides go with it.
- **Global-only flags:** `FeatureSeeder` deletes their override rows and keeps the global row.

### Side effects

`FeatureFlagUpdatedListener` reacts to global changes only:

- `FeatureUpdated` with a null scope
- `FeatureUpdatedForAllScopes`

An override, which is `FeatureUpdated` with a user or workgroup scope, never triggers a side effect such as the distributions view refresh.

## Endpoints

| Method | Path | Who | Does |
|---|---|---|---|
| GET | `/api/v1/features` | Any signed-in user | The caller's **effective** value for every flag: `{name: bool}`. |
| PUT | `/api/v1/features/{name}` | Admin | Sets the global value. `{"enabled": bool}` |
| GET | `/api/v1/features/overrides` | Admin | Every flag with its global value and overrides: `[{feature, global, overridable, users: [{id, name, email, enabled}], workgroups: [{id, name, enabled}]}]`, sorted by flag name. |
| PUT | `/api/v1/features/{name}/overrides` | Admin | Replaces the flag's overrides: `{"users": [{"id": int, "enabled": bool}], "workgroups": [{"id": int, "enabled": bool}]}`. Anything not listed is cleared. Send empty arrays to clear them all. |

The overrides listing makes three queries in total, whatever the number of flags: one for the `features` rows, one for the users and one for the workgroups.

Error responses for `PUT /overrides`:
- An unknown flag returns 404.
- A global-only flag returns 422.
- An unknown or repeated user or workgroup id, a missing `enabled`, or a missing `users` or `workgroups` list returns 422.

Each change is written to the activity log under `feature_flags`, with event `override_set` or `override_cleared` and properties `{feature, subject_type, subject_id, before: {enabled}, after: {enabled}}`. `null` means "no override". Overrides that a request leaves as they were aren't logged.

## Data protection

An override row links a user id (in `features.scope`) to whether that user can use a feature, which may include querying location and death data. The rows are removed when the user is deleted, and every change is audited in the activity log. The admin listing returns the name and email of every user with an override on any flag. `click-tracking-anonymous` is global-only, so overrides can't be used to attribute one person's clicks while everyone else's stay anonymous. Make sure this is covered by the deployment's DPIA and retention policy.
