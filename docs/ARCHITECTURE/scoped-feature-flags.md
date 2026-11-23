# Scoped feature flags

Most feature flags are global. A short allow-list can also be switched on or off for a single workgroup or a single user. For example, location and death queries can be enabled only for members of one workgroup, while every other user keeps the global setting.

## Which flags can be scoped

`config/features.php` lists them under `scopable`:

- `query-builder-use-location`
- `query-builder-use-death`

Every other flag always resolves to its global value, even if a scoped row exists for it. Some of those flags are read where there is no user at all: queued jobs, BUNNY polling, console commands.

## How a flag resolves for a user

`App\Services\Features\FeatureResolver` works it out in this order:

1. **Not scopable:** use the global value.
2. **User override:** if the user has one, use it, whether on or off.
3. **Workgroup overrides:** if any of the user's workgroups has the flag **on**, it is on. Otherwise, if any has it **off**, it is off. "On" wins when a user's workgroups disagree.
4. **Global value:** used when there are no overrides.

Clearing an override deletes the row, so the user or workgroup falls back to the next level down. `workgroups.active` is not taken into account, which matches how it is treated elsewhere.

## Storage

Overrides are ordinary Pennant rows in the existing `features` table:

| Level | `scope` |
|---|---|
| Global | `__laravel_null` |
| User | `App\Models\User\|{id}` |
| Workgroup | `App\Models\Workgroup\|{id}` |

Writes go through Pennant: `Feature::for($subject)->activate()`, `->deactivate()` and `->forget()`. `AppServiceProvider` still pins the default scope to null. Every existing `Feature::active()` call therefore keeps resolving globally, and only code that goes through `FeatureResolver` sees overrides.

### Rules that keep overrides intact

- **Never read a scopable flag with `Feature::for($user)->value()`.** If a flag has no `Feature::define` resolver, Pennant returns `false` when there is no row, so "no override" and "override off" look the same. Defining a resolver doesn't fix it, because Pennant would then store the fallback value and freeze it for that user. `FeatureResolver` reads the table directly instead.
- **Never use `activateForEveryone` / `deactivateForEveryone` for a scopable flag.** They run `UPDATE features SET value = ? WHERE name = ?`, which overwrites every override. `PUT /v1/features/{name}` writes only the global row, with `Feature::for(null)->activate()`.
- **Pennant must use the `database` store.** The resolver reads the `features` table directly.
- **Model morph-map serialisation must stay off.** The overrides listing finds users and workgroups by the `App\Models\User|` and `App\Models\Workgroup|` scope prefixes. Turning on Pennant's `useMorphMap()` would change those prefixes.

### Clean-up

- **Deleted users and workgroups:** the `HasFeatureOverrides` trait deletes their rows from a `deleted` model hook, because `scope` is a string with no foreign key.
- **Retired flags:** `FeatureSeeder` purges a retired flag across every scope, so its overrides go with it.

### Side effects

`FeatureFlagUpdatedListener` reacts to global changes only:

- `FeatureUpdated` with a null scope
- `FeatureUpdatedForAllScopes`

An override, which is `FeatureUpdated` with a user or workgroup scope, never triggers a side effect such as the distributions view refresh.

## Endpoints

| Method | Path | Who | Does |
|---|---|---|---|
| GET | `/api/v1/features` | Any signed-in user | The caller's **effective** value for every flag. Same shape as before: `{name: bool}`. |
| GET | `/api/v1/features/global` | Admin | The global value for every flag. |
| PUT | `/api/v1/features/{name}` | Admin | Sets the global value. `{"enabled": bool}` |
| GET | `/api/v1/features/overrides` | Admin | One entry per scopable flag: `[{feature, global, users: [{id, name, email, enabled}], workgroups: [{id, name, enabled}]}]`. Clients should use this as the list of scopable flags rather than keeping their own copy. |
| PUT | `/api/v1/features/{name}/overrides/users/{userId}` | Admin | Sets a user override. `{"enabled": bool}` |
| DELETE | `/api/v1/features/{name}/overrides/users/{userId}` | Admin | Clears a user override. |
| PUT | `/api/v1/features/{name}/overrides/workgroups/{workgroupId}` | Admin | Sets a workgroup override. `{"enabled": bool}` |
| DELETE | `/api/v1/features/{name}/overrides/workgroups/{workgroupId}` | Admin | Clears a workgroup override. |

Error responses:
- A flag that isn't scopable returns 422 from the set and clear endpoints.
- An unknown user or workgroup returns 404.
- Clearing an override that doesn't exist returns 200 and isn't logged.

Every set and clear is written to the activity log under `feature_flags`, with event `override_set` or `override_cleared` and properties `{feature, subject_type, subject_id, before: {enabled}, after: {enabled}}`. `null` means "no override".

## Where overrides take effect

- **Query submission:** `QuerySubmissionService` checks location and death for the submitting user. A task that needs a feature the user doesn't have is failed with the existing "not yet available" reason.
- **Web app:** it reads `/v1/features` per session. Pages that gate on these flags show the user's effective value, though the web app's cache can delay that by a few minutes. The API check at submission is always current.

## Data protection

An override row links a user id (in `features.scope`) to whether that user can query location and death data. The rows are removed when the user is deleted, and every change is audited in the activity log. Make sure this is covered by the deployment's DPIA and retention policy.
