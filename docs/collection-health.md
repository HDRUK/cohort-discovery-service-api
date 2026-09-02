# Collection health (host ping history)

Per-minute counts of collection-host polls, and a read endpoint that aggregates
them into a binned series a frontend can chart — at any bin width, always
normalised to average pings per minute so the numbers mean the same thing
whichever width is asked for.

## Why this exists

Collection hosts (BUNNY) prove they are alive as a side effect of polling for
work — there is no dedicated heartbeat endpoint. Before this, the only record was
`collection_activity_logs`: a single row per `(collection, task_type)` whose
`updated_at` is touched on every poll. That answers "is this host alive right
now?" and nothing else. It cannot tell you whether a host was flapping
overnight, went quiet for twenty minutes at 03:00, or is polling once an hour
instead of at its configured cadence.

`collection_ping_buckets` keeps that history at minute resolution: one row per
`(collection_id, task_type, minute)`, incremented in place. Coarser bins are
truncations computed in SQL at query time, so there is no rollup table to keep in
step.

Counts rather than one row per ping, because poll frequency is a fixed cadence —
the signal is "did we get roughly the expected number of pings this minute", not
individual ping identity. Volume is then independent of poll rate at ~2,880
rows/day/collection, and the write is one atomic
`INSERT … ON DUPLICATE KEY UPDATE n = n + 1` with no read.

## Endpoint

```
GET /api/v1/collections/{id}/health
```

- **Auth:** standard bearer JWT (same as the rest of `/v1`).
- **Authorisation:** `CollectionPolicy::view` — admin, or a user attached to the
  collection's custodian. **Workgroup researchers get 403**, deliberately: host
  telemetry is a custodian-facing concern, not a researcher-facing one. Widening
  it later is a one-line swap to the `visibleToUser` scope.
- **`{id}`:** either the numeric collection `id` or the UUID `pid`, resolved by
  the `ResolvesByIdOrPid` trait.
- **Caller:** ordinary server-to-server call, so it works from a Next.js Server
  Action exactly like every other `/v1` endpoint.

### Query parameters

| Param    | Type   | Rules                                            | Default   | Notes |
|----------|--------|--------------------------------------------------|-----------|-------|
| `bin`    | string | a named unit — `minute`, `hour`, `day`, `week`, `month` — or a multiple `^[1-9]\d{0,4}[mhdw]$` such as `10m`, `6h`, `2d`, `4w` | `minute`  | Anything else is **422**. Resolved by `HealthBinWidth`. |
| `window` | string | `^[1-9]\d*[mhdw]$` — e.g. `30m`, `24h`, `7d`, `2w` | `1h`    | Ignored when `from` is supplied. |
| `from`   | string | ISO-8601 date-time                                | —         | Overrides `window`. |
| `to`     | string | ISO-8601 date-time                                | now       | — |

Two range modes, each taking the reading that is natural for how it is asked:

- **`window`** is a *duration*, and returns exactly that many bins, ending at the
  current (partial) bin. `?window=1h` with minute bins is **60** bins, not 61 —
  the bin containing the window start is excluded.
- **`from`/`to`** is an *explicit span*, inclusive at both ends.
  `?from=08:00&to=10:00` with hour bins is **3** bins (08:00, 09:00, 10:00).

Requests whose bin and range would exceed `COLLECTION_HEALTH_MAX_BINS` (default
2000) are rejected with **422** rather than served, so `?bin=minute&window=90d`
fails instead of returning ~130k points. Use a coarser bin for long ranges.

### Custom bin widths

`bin` takes a multiple as well as a named unit, so the resolution does not have
to jump straight from a minute to an hour: `?bin=10m` gives ten-minute bins,
`?bin=6h` quarter-day bins, `?bin=2d`, `?bin=4w`, and so on. The multiplier is
capped at five digits and must be positive.

**Custom widths snap to an absolute grid, not to the request.** The grid is
anchored at the Unix epoch (and at the Monday before it for `w`), so a `10m` bin
always starts at `:00`, `:10`, `:20`…, never ten minutes back from whenever the
request happened to arrive. Two overlapping requests therefore return the same
bin boundaries and can be cached or compared point-for-point. As a consequence a
width that does not divide its next unit up drifts predictably rather than
tracking the calendar — `7d` bins start on Thursdays, because the epoch was a
Thursday. Use `1w`/`week` for Monday-aligned weeks.

Two details worth knowing:

- **A multiple of one normalises to its named unit.** `?bin=1h` returns
  `"bin": "hour"`, and behaves identically — including `week`'s Monday start and
  `month`'s 1st-of-month start, which are calendar boundaries rather than grid
  offsets.
- **`month` has no multiple form.** It is the one variable-width unit, so `2mo`
  is not a fixed number of minutes and is rejected with **422**. `mo` is not a
  recognised suffix at all — `m` is minutes, matching `window`.

`window` semantics are unchanged and still count bins, not units, so
`?bin=10m&window=1h` is 6 bins. When the window is not a whole multiple of the
width the partially covered first bin is dropped rather than half-filled — you
get the bins that start strictly after the window start.

The bin ceiling applies exactly as before and is the thing to reach for when
choosing a width: `?bin=10m` covers a fortnight in ~2,000 bins, where
`?bin=minute` tops out at a bit under 1.4 days.

### Response

```json
{
  "message": "success",
  "data": {
    "collection_id": 12,
    "bin": "hour",
    "from": "2026-09-02T04:00:00Z",
    "to":   "2026-09-02T10:00:00Z",
    "summary": {
      "a": { "last_ping_at": "2026-09-02T09:59:41Z", "pings": 4236,
             "per_minute": 11.767, "minutes": 360, "silent_minutes": 3,
             "bins": 6, "empty_bins": 0, "longest_gap_bins": 0 },
      "b": { "last_ping_at": null, "pings": 0,
             "per_minute": 0, "minutes": 360, "silent_minutes": 360,
             "bins": 6, "empty_bins": 6, "longest_gap_bins": 6 }
    },
    "series": {
      "a": [ { "bin": "2026-09-02T04:00:00Z", "n": 706, "minutes": 60,
               "silent_minutes": 0, "per_minute": 11.767 }, "…" ],
      "b": [ { "bin": "2026-09-02T04:00:00Z", "n": 0, "minutes": 60,
               "silent_minutes": 60, "per_minute": 0 }, "…" ]
    }
  }
}
```

| Field | Meaning |
|---|---|
| `collection_id` | Numeric id, whichever identifier was used in the path. |
| `bin` | Echo of the resolved bin width — the multiple as sent (`10m`), or the unit name when the width is one (`1h` echoes as `hour`). |
| `from` | Start of the first bin (inclusive). |
| `to` | **Exclusive** — the start of the bin *after* the last one. |
| `summary.{a,b}.last_ping_at` | Latest ping within the range, or `null` if none. |
| `summary.{a,b}.pings` | Total pings across the range. |
| `summary.{a,b}.per_minute` | Average pings per minute across the range: `pings / minutes`. `null` only when `minutes` is 0. |
| `summary.{a,b}.minutes` | Total elapsed minutes the range covers. |
| `summary.{a,b}.silent_minutes` | Minutes across the range that had zero pings. |
| `summary.{a,b}.bins` | Number of bins returned. |
| `summary.{a,b}.empty_bins` | Bins with zero pings. |
| `summary.{a,b}.longest_gap_bins` | Longest run of consecutive zero bins. |
| `series.{a,b}[].bin` | Bin **start** time. |
| `series.{a,b}[].n` | Pings summed across that bin. |
| `series.{a,b}[].minutes` | Elapsed minutes the bin covers — its width, except for the final in-progress bin. |
| `series.{a,b}[].silent_minutes` | Minutes within the bin that had zero pings. |
| `series.{a,b}[].per_minute` | `n / minutes`, 3dp. `null` when `minutes` is 0. |

Contract details a consumer can rely on:

- **`a` and `b` are task types.** `a` = cohort query jobs, `b` = distributions
  jobs (see the `TaskType` enum). Both keys are always present.
- **`per_minute` is the value to chart, and it means the same thing at every bin
  width.** `n` scales with the bin — an hour bin is ~60× a minute bin for the
  same host — so charting `n` makes the bin control a scale control. `per_minute`
  does not, which is what lets a user change bin and range freely.
- **The final bin is always in progress**, so its `minutes` is the elapsed part
  rather than the full width and `per_minute` is normalised accordingly. Divide
  `n` by the nominal width yourself and a healthy host's last point dives toward
  zero.
- **Whole rates serialise without a fractional part** — `10`, not `10.0`. They
  are JSON numbers either way.
- **`series` is zero-filled and sorted ascending.** Every bin in the range
  appears, so a silent host is a run of `n: 0`, never a missing entry. Hence
  `series[t].length === summary[t].bins` always holds, and the array can be fed
  to a chart without gap-filling. The zero-filling is the point — a gap is only
  visible if the empty bins are there.
- **`to` is exclusive**, so the last rendered bin is the final `series` entry,
  not `to`. Do not draw a point at `to`.
- **All times are UTC, ISO-8601 Zulu.** `APP_TIMEZONE` is UTC and bucket
  boundaries are stored in UTC.
- **A never-polled collection returns 200** with an all-zero series and
  `last_ping_at: null` — not a 404, not an empty body.
- **A bin wholly in the future** — only reachable with a `from`/`to` range that
  runs past now — reports `minutes: 0` and `per_minute: null`, keeping "no data
  can exist yet" distinct from "the host was silent". Those bins contribute
  nothing to `summary.per_minute`.
- `week` bins start Monday; `month` bins start on the 1st; custom widths start on
  their epoch grid. The SQL truncation and the PHP zero-fill boundary must agree
  exactly or the series silently reads as all zeros, so week, month and the
  multiple form are each pinned by tests.

### Status codes

| Code | When | Body |
|------|------|------|
| 200 | OK | as above |
| 403 | Neither admin nor attached to the collection's custodian | `{"message": "forbidden", "data": null}` |
| 404 | Unknown collection | `{"message": "not found", "data": null}` |
| 422 | Bad `bin`/`window`, `from` later than `to`, empty range, or too many bins | Laravel validation shape: `{"message": "...", "errors": {"bin": [...]}}` |
| 500 | Unexpected | `{"message": "unexpected error", "data": ...}` |

Note the 422 body is Laravel's `ValidationException` rendering (`message` +
`errors`), **not** the `Responses` trait's envelope — over-wide ranges report
under `errors.bin`, malformed windows under `errors.window`, bad spans under
`errors.from`.

### Example

Last 24 hours at hourly resolution, from a Next.js Server Action that injects the
bearer token server-side:

```ts
const res = await fetch(
  `${API_BASE_URL}/api/v1/collections/${pid}/health?bin=hour&window=24h`,
  { headers: { Authorization: `Bearer ${token}` } },
);
const { data } = await res.json();

// 24 points, ascending, zero-filled - safe to chart directly.
// per_minute, not n: the y-axis then means the same thing at any bin width.
const points = data.series.a
  .filter((p) => p.per_minute !== null)
  .map((p) => ({ x: new Date(p.bin), y: p.per_minute }));

const rate = data.summary.a.per_minute;            // e.g. 11.767 polls/min
const isSilent = data.summary.a.last_ping_at === null;
```

## Interpreting the numbers

**A ping is a poll for work, not a query execution.** A host polls on a cadence
configured at the custodian's site, so a healthy host holds a roughly constant
`per_minute`. Three consequences:

1. **`per_minute` is comparable across bin widths and across time, but not across
   collections.** The API does not know any host's configured poll interval, so
   there is no stored "expected" value to score against — a true uptime
   percentage cannot be derived from this data alone. Do not render a percentage
   that implies one, and do not put two collections' rates on the same axis
   without saying they poll at different cadences.
2. **A dip in `per_minute` is ambiguous on its own.** It means either the host
   went quiet or it slowed down. `silent_minutes` separates the two: rate down
   *and* silent minutes up is an outage; rate down with `silent_minutes: 0` is a
   host still polling, just less often.
3. **`silent_minutes` is the width-independent gap signal; `empty_bins` and
   `longest_gap_bins` are bin-relative.** A bin only counts as empty if *nothing*
   arrived for its whole width, so at `hour` a 20-minute outage leaves
   `empty_bins: 0` while `silent_minutes` still reports 20. Multiply
   `longest_gap_bins` by the bin width before showing it as a duration, and
   prefer `silent_minutes` for "how much silence" at anything coarser than a
   minute.

Pick the width from the question being asked: `minute` or `10m` for "is this host
flapping right now", `hour` or `day` for "was it up yesterday". The rate is the
same either way — the wider bin just smooths it.

**This is independent of collection status.** Suspension is still driven by the
older `collection_activity_logs` heartbeat: `CollectionNoActivityMonitor`
suspends a collection after `COLLECTION_INACTIVITY_MINUTES` (default 30) with no
**type A** polling, and `TaskController::nextJob` un-suspends it on the next
type-A poll. A host polling only `b` therefore looks alive in this endpoint's `b`
series while still being suspended. If both status and health appear on one
screen they can legitimately disagree — present them as distinct facts.

**Retention bounds how far back you can look.** Default 30 days
(`COLLECTION_PING_RETENTION_DAYS`); older ranges return zeros rather than an
error. Because of that, `week` bins realistically cover ~4 and `month` bins never
cover a full month, so check the deployed retention before exposing those two in
a bin picker.

## Configuration

| Env var | Default | Purpose |
|---|---|---|
| `COLLECTION_PING_RETENTION_DAYS` | 30 | Age at which minute buckets are pruned. |
| `COLLECTION_HEALTH_MAX_BINS` | 2000 | Ceiling on bins per health request. |

Both are read through `config/system.php` with in-code fallbacks, so a stale
config cache cannot break them.

## Retention job

`PruneCollectionPingBucketsJob` deletes buckets older than the retention window
in capped batches. There is **no Laravel scheduler in this project** — periodic
work is triggered externally:

```
POST /api/v1/services/caller/prune-collection-ping-buckets
```

registered in `ApiCommandDispatcher` alongside `task-cleanup-job`. **The job is
inert until that URL is added to the external cron**, and the table then grows
unbounded at ~2,880 rows/day/collection.

## Not yet built

- **No fleet-wide variant.** One request per collection. A dashboard covering
  many collections needs a grouped cross-collection aggregate on the API side —
  raise that rather than firing N requests.
- **Not feature-flagged.** The endpoint is additive and read-only.
- **`collection_activity_logs` is untouched.** Once ping buckets are proven in
  production it becomes derivable from them and can be retired.

## Where this lives

| Concern | File |
|---|---|
| Table | `database/migrations/2026_09_01_100000_create_collection_ping_buckets_table.php` |
| Model | `app/Models/CollectionPingBucket.php` |
| Write path | `app/Services/Collections/CollectionPingRecorder.php`, called from `app/Http/Controllers/Api/V1/TaskController.php` (`nextJob`) |
| Bin units | `app/Enums/HealthBin.php` |
| Bin width parsing and grid alignment | `app/Support/HealthBinWidth.php` |
| Read logic | `app/Services/Collections/CollectionHealthService.php` |
| Controller | `app/Http/Controllers/Api/V1/CollectionHealthController.php` |
| Route | `routes/api.php` (main `decode.jwt` group) |
| Retention | `app/Jobs/PruneCollectionPingBucketsJob.php`, `app/Console/Commands/PruneCollectionPingBuckets.php` |
| Tests | `tests/Feature/Api/V1/CollectionHealthControllerTest.php`, `tests/Unit/PruneCollectionPingBucketsJobTest.php`, plus ping-recording cases in `tests/Feature/TaskControllerTest.php` |
