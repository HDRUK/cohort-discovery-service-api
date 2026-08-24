# Click / action tracking

A single generic endpoint the frontend uses to record that a user performed an
action ("clicked something") on a domain object. The frontend names the subject
and the action; the backend writes one activity-log entry. This replaces the old
single-purpose `POST /v1/queries/{pid}/click-through`.

## Endpoint

```
POST /api/v1/clicks
```

- **Auth:** standard bearer JWT (same as the rest of `/v1`). Any authenticated
  user may record a click on any existing subject — there is **no ownership
  check** (user B may click user A's task).
- **Origin:** the request must come from a trusted browser. The `Origin` header
  must match the configured allowlist and the browser-set `Sec-Fetch-Site`
  header must be present. A normal `fetch`/`XHR` from the app sends both
  automatically; you do not set them manually.

### Request body

| Field          | Type   | Rules                                              | Notes |
|----------------|--------|----------------------------------------------------|-------|
| `subject_type` | string | required, `^[a-zA-Z_]+$`, max 64                   | Name of an `App\Models` model. Resolved as `App\Models\{StudlyName}` (`task` → `Task`, `collection` → `Collection`, …). **Any** model is accepted — there is no allowlist. |
| `subject_id`   | string \| number | required, integer id **or** UUID pid    | Either the numeric `id` or the `pid` of the subject. |
| `action`       | string | required, `^[a-z0-9_]+$`, max 64                   | Free-form `snake_case` label — the FE decides what it means. |
| `description`  | string | optional, max 500                                  | Free-form human-readable note. Defaults to `click_<action>`. |

A `subject_type` that does not resolve to an Eloquent model returns **404**
(there is no allowlist to validate against). Illegal characters (digits,
namespace separators) are rejected with **422**.

### Example

User follows the collection link shown against a task in the results view:

```ts
await fetch(`${API_BASE_URL}/api/v1/clicks`, {
  method: "POST",
  headers: {
    "Content-Type": "application/json",
    Authorization: `Bearer ${token}`,
  },
  body: JSON.stringify({
    subject_type: "task",
    subject_id: task.pid,               // id or pid both work
    action: "clicked_collection_link",
    description: "User followed the collection link on the results page",
  }),
});
```

### Responses

| Status | Meaning |
|--------|---------|
| 200 | Click logged (or ignored as a recent duplicate). |
| 403 | Request did not come from an allowed browser origin. |
| 404 | Subject of that type/id does not exist. |
| 422 | Validation error (unknown `subject_type`, bad `action`, etc.). |
| 429 | Too many clicks — rate limit exceeded. |
| 500 | Unexpected error. |

## How it is stored

Each accepted click writes one row to the `activity_log` table (Spatie
activitylog):

- `log_name` = `clicks`
- `event` = the `action` you sent
- `description` = your `description`, or `click_<action>` if omitted
- `subject_type` / `subject_id` = the resolved model (e.g. `App\Models\Task`)
- `causer_type` / `causer_id` = the authenticated user (see anonymity below)
- `properties` = `{ "subject_type": "<type>" }`

### Inferring related objects from the subject

You do **not** need to send the query and collection separately when tracking a
task click. A `task` subject is enough — analytics can recover both from the
`Task` relationships:

```
Task -> submittedQuery (the Query, which carries user_id)
Task -> collection     (the Collection)
```

So `subject_type: "task"` captures the whole context of a "clicked the
collection link on a task" event.

## Anonymity

Controlled by the `click-tracking-anonymous` feature flag:

- **Default (`false`) — attributed:** the causer (user) is recorded on the entry.
- **`true` — anonymous:** the causer is nulled (`causer_id`/`causer_type` = null).

Admins toggle the flag; no deploy needed.

> **Privacy caveat.** Anonymous mode only nulls the causer. A `task` or `query`
> subject is still linkable to a user, because a Query carries `user_id`
> (`Task -> submittedQuery -> user_id`). Row-level subject storage cannot be
> fully anonymous for those types — true anonymity would require aggregating the
> data rather than storing per-click subjects. Bear this in mind before relying
> on the flag for a DPIA guarantee.

## Anti-abuse

- **Browser origin gate** (`EnsureBrowserOrigin`): requires an allowlisted
  `Origin` plus a `Sec-Fetch-Site` header. This blocks casual scripted abuse and
  cross-site calls. It is defence-in-depth, not proof-of-browser — a caller with
  a valid JWT can still forge these headers with a tool like curl.
- **Rate limit** (`throttle:click-tracking`): `CLICK_RATE_LIMIT` requests per
  minute (default 60), keyed by user (falling back to IP).
- **Dedup**: an identical click (same causer + subject + action) within
  `CLICK_DEDUP_SECONDS` (default 5) is treated as a duplicate and not logged
  again — it still returns 200.

## ⚠️ Integration assumption (read before wiring the FE)

The browser-origin gate assumes the click POST is made **directly from the
browser to this API** (e.g. a `fetch`/`navigator.sendBeacon` call), so that the
browser attaches the `Origin` and `Sec-Fetch-*` headers.

The rest of the app talks to the API **server-side** (Next.js Server Actions →
API). A request made from the Next.js server has **no** `Origin`/`Sec-Fetch`
header, so the gate would reject it with 403. Two consequences:

1. **Call `/v1/clicks` from the browser, not through a Server Action** — otherwise
   the browser-origin check can't work.
2. **CORS must allow the FE origin.** This API currently has no `config/cors.php`.
   A browser POST with `Authorization` + `application/json` triggers a CORS
   preflight, which the API must answer for the FE origin, or the browser blocks
   the call before it arrives. Add CORS config (allowed origins matching
   `FRONTEND_URLS`) as part of wiring this up.

If instead you decide to proxy clicks through the Next.js server, drop the
`browser.origin` middleware and replace it with a shared-secret header check
between the Next.js server and the API.

## Configuration

`config/clicks.php`, driven by env:

| Env | Default | Purpose |
|-----|---------|---------|
| `FRONTEND_URLS` | — | Comma-separated allowlist of browser origins, e.g. `https://web.example,http://localhost:3000`. |
| `CLICK_RATE_LIMIT` | `60` | Max click POSTs per minute per user/IP. |
| `CLICK_DEDUP_SECONDS` | `5` | Duplicate-suppression window. |

## Subject types

There is nothing to register — `subject_type` is resolved dynamically to
`App\Models\{StudlyName}`, so any existing model can be a click subject the
moment the FE sends its name.

> **Security note (deliberate trade-off).** Because there is no allowlist, any
> authenticated caller can record a click against **any** model — including
> `User`, `Custodian`, etc. — and can probe which model names exist (a non-model
> name returns 404). This was chosen for flexibility. Combined with the
> anonymity caveat above, treat the click log as potentially linking users to a
> wide range of objects. If this becomes a concern, reintroduce an allowlist
> (e.g. a config array validated with `Rule::in(...)`).
