# Scoped feature flags

Any feature flag that isn't [global-only](#global-only-flags) can be switched on or off for a single workgroup or a single user, as well as globally. For example, location and death queries can be enabled only for members of one workgroup, while every other user keeps the global setting.

## How a flag resolves for a user

`App\Services\Features\FeatureResolver` works it out in this order:

1. **User override:** if the user has one, use it, whether on or off.
2. **Workgroup overrides:** if any of the user's workgroups has the flag **on**, it is on. Otherwise, if any has it **off**, it is off. "On" wins when a user's workgroups disagree.
3. **Global value:** used when there are no overrides.

Clearing an override deletes the row, so the user or workgroup falls back to the next level down. `workgroups.active` is not taken into account, which matches how it is treated elsewhere.

The user defaults to the signed-in user. Pass one explicitly only when acting for someone other than the caller, as `QuerySubmissionService` and `QueryRateLimiter` do. With no user (queued jobs, console commands, BUNNY polling) only the global value applies.

## Global-only flags

`config/features.php` lists every flag under `flags`, with its `default` and, optionally, `'overridable' => false`. `FeatureServiceProvider` registers each one with `Feature::define`, and `FeatureSeeder` reconciles the table against them. A flag with `overridable` set to false can't be overridden:

| Flag | Why |
|---|---|
| `click-tracking-anonymous` | An override set to off would log one user's or one workgroup's clicks with their user id while everyone else stays anonymous. That is targeted tracking of individuals. |
| `flatten-nested-groups` | Read in translate and in BUNNY polling, which must agree. |
| `collection-task-backlog-limit` | Read in BUNNY polling, the backlog monitor, distributions and query submission. |
| `distribution-use-central-domain` | Read only in a queued job. |
| `constrain-for-bunny-v1` | Describes what the collections' BUNNY can run, not who is asking. An override could let someone build queries that BUNNY v1 can't run, and the API doesn't check them. See `collection-capabilities.md`. |

For these flags:
- `FeatureResolver` returns the global value and ignores any override rows. Call sites that never have a user, such as `ClickController`, read them with `Feature::active()`.
- `PUT /v1/features/{name}/overrides` returns 422.
- The overrides listing returns `overridable: false`.
- `FeatureSeeder` deletes any override rows they have, so stale overrides can't come back if a flag is later made overridable.

Every flag is overridable unless it sets `'overridable' => false`. Do that if an override would track individuals, or if the flag is read anywhere without a user. The values are literals rather than `env()`, so a deployment can't switch this off.

Flags can't be scoped to collections. Behaviour that depends on the collection a query runs against is a collection capability, checked alongside the flag. See `collection-capabilities.md`.

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

Writes go through Pennant: `Feature::for($subject)->activate()`, `->deactivate()` and `->forget()`. `FeatureServiceProvider` pins the default scope to null. Every `Feature::active()` call therefore keeps resolving globally, and only code that goes through `FeatureResolver` sees overrides.

### Defining flags and defaults

`FeatureServiceProvider` calls `Feature::define` for every flag in `config/features.php`. The database stays the source of truth for values:

- A stored row always wins. The definition only runs when a flag has no row for the scope being read, and then Pennant stores the config default for that scope.
- Global rows are therefore created the first time a flag is read, normally by the first `GET /api/v1/features` or admin listing after a deploy. A new flag needs only a line in `config/features.php`, with no seeder change and no deployment step.
- Changing a stored value in the database takes effect on the next web request, because Pennant flushes its in-memory cache when each request arrives. In queue workers the cache is flushed after every job attempt, whether it succeeded or failed.
- Deleting a global row resets that flag to its config default, not to off. Values must be valid JSON (`true` or `false`): one bad row makes `GET /api/v1/features` fail.
- The definition's closure accepts only the null scope. Reading a flag through Pennant for a user or workgroup that has no stored row, for example `Feature::for($user)->value()`, throws a `TypeError` instead of storing a frozen value. It does not stop the read when that scope already has a row, which skips workgroup and global precedence, so still read user-facing flags through `FeatureResolver`.

### Rules that keep overrides intact

- **Read user-facing flags through `FeatureResolver`, never `Feature::for($user)->value()`.** Pennant holds one value per flag and scope and can't say whether a user has an override without storing a row. `FeatureResolver` reads the global values through Pennant and the override rows with one query. It defaults to the signed-in user.
- **The resolver reads each flag's override rows once per request.** It is bound as a scoped singleton, so listing many users, as the users index does for the query quota, doesn't query the `features` table per user. `FeatureUpdated`, `FeatureUpdatedForAllScopes` and `FeatureDeleted` flush it. Writing to the `features` table directly bypasses that within the request, so go through Pennant.
- **Never use `activateForEveryone` / `deactivateForEveryone` in application code.** They run `UPDATE features SET value = ? WHERE name = ?`, which overwrites every override. `PUT /v1/features/{name}` writes only the global row, with `Feature::for(null)->activate()`.
- **Never use `Feature::globally()`.** It stores under `__laravel_global`, a scope nothing reads.
- **Pennant must use the `database` store.** The resolver reads the `features` table directly.
- **Model morph-map serialisation must stay off.** The overrides listing finds users and workgroups by the `App\Models\User|` and `App\Models\Workgroup|` scope prefixes. Turning on Pennant's `useMorphMap()` would change those prefixes.

### Clean-up

- **Deleted users and workgroups:** the `HasFeatureOverrides` trait deletes their rows from a `deleted` model hook, because `scope` is a string with no foreign key. Mass deletes (`User::where(...)->delete()`) and raw SQL don't fire model events, so they leave orphaned override rows behind. The rows are harmless, because nothing resolves to a user or workgroup that no longer exists, but they are never cleaned up.
- **Retired flags:** remove the flag from `config/features.php` and re-run `FeatureSeeder` in a deployment step. It purges every stored flag that is no longer defined, across every scope, so its overrides go with it. The seeder does nothing if no flags are defined, so a broken config can't empty the table.
- **Global-only flags:** `FeatureSeeder` deletes their override rows and keeps the global row.

### Side effects

`FeatureAdminService::setGlobal` is the only place that reacts to a flag changing. When `distribution-use-central-domain` changes value, it dispatches `RefreshLatestDistributionsView` after the transaction commits, so a worker can't rebuild the view from the old value. Saving the same value again dispatches nothing.

The refresh is therefore not triggered by seeding, tinker, tests, a direct `Feature::activate()` or an edit to the `features` table. After any of those, dispatch `RefreshLatestDistributionsView` by hand. Overrides never trigger a side effect, because `distribution-use-central-domain` is global-only.

## Endpoints

The admin endpoints are authorised by `FeaturePolicy::manage`, which allows the `admin` role.

| Method | Path | Who | Does |
|---|---|---|---|
| GET | `/api/v1/features` | Any signed-in user | The caller's **effective** value for every flag: `{name: bool}`. |
| PUT | `/api/v1/features/{name}` | Admin | Sets the global value of an existing flag. `{"enabled": bool}`. An unknown flag returns 404, so a mistyped name can't create a new flag. |
| GET | `/api/v1/features/overrides` | Admin | Every flag with its global value and overrides: `[{feature, global, overridable, users: [{id, name, email, enabled}], workgroups: [{id, name, enabled}]}]`, sorted by flag name, including flags that have never been read. |
| PUT | `/api/v1/features/{name}/overrides` | Admin | Replaces the flag's overrides: `{"users": [{"id": int, "enabled": bool}], "workgroups": [{"id": int, "enabled": bool}]}`. Anything not listed is cleared. Send empty arrays to clear them all. |

The overrides listing makes three queries in total, whatever the number of flags: one for the `features` rows, one for the users and one for the workgroups.

Error responses for `PUT /overrides`:
- An unknown flag returns 404.
- A global-only flag returns 422.
- An unknown or repeated user or workgroup id, a missing `enabled`, or a missing `users` or `workgroups` list returns 422.

The request reads the flag once, so its global row exists, then locks that row while it reads and replaces the overrides. Two admins saving the same flag at once are handled one after the other, and the second save replaces the first.

Each change is written to the activity log under `feature_flags`, with event `override_set` or `override_cleared` and properties `{feature, subject_type, subject_id, before: {enabled}, after: {enabled}}`. `null` means "no override". Overrides that a request leaves as they were aren't logged. A global `PUT` is logged as `updated` with `{feature, before: {enabled}, after: {enabled}}` only when the value changes.

A failed write is logged with event `failed` and description `feature_flag_update_failed` (global) or `feature_flag_overrides_update_failed` (overrides), with properties `{feature, error: {class, message}}`. The entry is written after the transaction has rolled back, so it survives the failure. A failed `GET` of the overrides listing is only written to the Laravel log. Denied requests are not activity-logged: the request audit already records them with their 403 status.

## Data protection

An override row links a user id (in `features.scope`) to whether that user can use a feature, which may include querying location and death data. The rows are removed when the user is deleted, and every change is audited in the activity log. The admin listing returns the name and email of every user with an override on any flag. `click-tracking-anonymous` is global-only, so overrides can't be used to attribute one person's clicks while everyone else's stay anonymous. Make sure this is covered by the deployment's DPIA and retention policy.
