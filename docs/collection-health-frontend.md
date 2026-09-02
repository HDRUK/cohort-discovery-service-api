# Collection health — frontend integration

How to consume `GET /api/v1/collections/{id}/health`. The API-side reasoning is in
[collection-health.md](collection-health.md) and
[collection-health-bins-and-rates.md](collection-health-bins-and-rates.md); this
page is about what to build.

> ## The one rule
>
> **Plot `per_minute`. Never plot `n`.**
>
> The y-axis is **average pings per minute** at every bin width. That is the
> default and only unit — the API normalises before it responds, so `bin` is a
> resolution control, never a scale control. Switching from `minute` to `hour`
> re-shapes the line (smoother, fewer points); it does not move it up or down.
>
> `n` is the raw sum for the bin. It belongs in a tooltip — "706 polls this hour" —
> and nowhere else.

Charting `n` instead reads as a 60× jump the moment someone changes the bin, and
two views of the same host can no longer be compared. That is the bug this
contract exists to prevent.

## The request

```
GET /api/v1/collections/{idOrPid}/health?bin=<width>&window=<duration>
GET /api/v1/collections/{idOrPid}/health?bin=<width>&from=<iso>&to=<iso>
```

Standard bearer JWT, same as the rest of `/v1` — an ordinary server-to-server call
from a Server Action. `{idOrPid}` takes either the numeric id or the UUID `pid`.

Two independent controls, which is the whole UX:

| Control | Param | Values |
|---|---|---|
| **Resolution** | `bin` | `minute`, `hour`, `day`, `week`, `month`, or any multiple of `m`/`h`/`d`/`w` — `10m`, `20m`, `30m`, `6h`, `2d`, `4w`. Default `minute`. |
| **Range** | `window` | A duration back from now: `30m`, `6h`, `24h`, `7d`, `2w`. Default `1h`. |
| | `from` / `to` | An explicit ISO-8601 span instead. `from` wins if both are sent. |

Changing one does not constrain the other, subject only to the bin ceiling below.

### Suggested bin ladder

`1m · 10m · 30m · 1h · 6h · 1d`

Enough to go from "is it flapping right now" to "was it up last week" in six
steps, with no gap where the resolution jumps 60×. Worth knowing:

- **`month` is not worth offering.** Default retention is 30 days, so a month bin
  never covers a full month.
- **`week` covers about four** before it runs out of retained data.
- **`7d` is not the same as `1w`.** Multiples align to an absolute grid anchored at
  the epoch, which was a Thursday, so `7d` bins start on Thursdays. Use `1w` (or
  `week`) when you want Monday-aligned weeks.

### The bin ceiling

A request whose bin and range would produce more than **2,000** bins is rejected
with a 422 rather than served. Pair the two controls so the product stays under it:

| Range | Widths that fit |
|---|---|
| 1 hour | `1m` (60) and coarser |
| 24 hours | `1m` (1,440), `10m` (144), `1h` (24) |
| 7 days | `10m` (1,008), `30m` (336), `1h` (168) |
| 14 days | `15m` (1,344), `30m` (672) |
| 30 days | `30m` (1,440), `1h` (720), `6h` (120), `1d` (30) |

The cleanest handling is to disable the fine widths when the selected range cannot
support them, rather than letting the request 422. If one does slip through, the
422 body is Laravel's validation shape and the message under `errors.bin` names
the bin count it would have needed:

```json
{ "message": "…", "errors": { "bin": ["The requested range needs about 12960 10m bins, which exceeds the maximum of 2000. Use a coarser bin or a shorter range."] } }
```

## The response

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
      "b": [ "…" ]
    }
  }
}
```

```ts
type HealthPoint = {
  bin: string;              // ISO-8601 Zulu, bin START
  n: number;                // pings summed across the bin - tooltip only
  minutes: number;          // elapsed minutes the bin covers
  silent_minutes: number;   // of those, how many had zero pings
  per_minute: number | null; // n / minutes - THIS is the series
};

type HealthSummary = {
  last_ping_at: string | null;
  pings: number;
  per_minute: number | null; // minute-weighted average over the whole range
  minutes: number;
  silent_minutes: number;
  bins: number;
  empty_bins: number;
  longest_gap_bins: number;
};

type CollectionHealth = {
  collection_id: number;
  bin: string;              // echo of the resolved width
  from: string;
  to: string;               // EXCLUSIVE
  summary: Record<'a' | 'b', HealthSummary>;
  series: Record<'a' | 'b', HealthPoint[]>;
};
```

### `a` and `b` are two different hosts' concerns

Both keys are always present. `a` is cohort query job polling, `b` is
distributions job polling. They are separate series and should be rendered as
such — two lines, or a toggle.

Only **`a`** drives collection status: a collection is auto-suspended after 30
minutes with no type-A polling. A host polling only `b` looks alive in the `b`
series while still being suspended, so **status and health can legitimately
disagree** — present them as distinct facts rather than deriving one from the
other.

## Rendering rules

**The series is zero-filled and ascending.** Every bin in the range is present,
so silence is a run of `per_minute: 0`, never a missing entry.
`series[t].length === summary[t].bins` always holds — feed it straight to a chart
with no gap-filling. A gap is only visible *because* those zero points are there,
so do not filter them out.

**`to` is exclusive.** The last point is the final `series` entry, not `to`. Do
not draw anything at `to`.

**All times are UTC, ISO-8601 Zulu**, and `bin` is the bin's **start**. For a bar
chart, the bar spans `bin` to `bin + minutes`.

**Skip `null` rates, don't zero them.** `per_minute` is `null` only when
`minutes === 0`, i.e. a bin wholly in the future — reachable if a date picker
allows a `to` past now. `null` means "no data can exist yet", which is not the
same as silence, so drop those points rather than plotting them as 0.

**The final point is a partial bin, already normalised.** Its `minutes` is less
than the bin width, and `per_minute` divides by the elapsed part — so a healthy
host holds a flat line into the present instead of the last point diving toward
zero. Render it normally. Optionally style it as provisional (`minutes < width`
detects it) since it is a smaller sample and will settle as the bin fills.

**Whole rates arrive without a decimal point** — `10`, not `10.0`. JSON numbers
either way; format on display.

**`data.bin` is normalised, so it may not round-trip.** Send `10m` and it echoes
`10m`; send `1h` and it echoes `hour`, because a multiple of one *is* the named
unit. If your picker's values are `1m`/`1h`/`1d`, drive its selected state from
your own state, not from `data.bin`.

## Reading the numbers

**A ping is a poll for work, not a query execution.** A host polls on a cadence
configured at the custodian's site, so a healthy host holds a roughly constant
`per_minute`.

**A dip is ambiguous on its own** — either the host went quiet, or it slowed down.
`silent_minutes` separates the two, and this is the pair a status badge should key
off:

| `per_minute` | `silent_minutes` | Reading |
|---|---|---|
| steady | `0` | healthy |
| down | up | **outage** — real silence within the bin |
| down | `0` | still polling, slower cadence |
| `0` | `= minutes` | fully silent for the whole bin |

`silent_minutes` is the gap signal that survives the bin choice. `empty_bins` and
`longest_gap_bins` are bin-relative: a bin only counts as empty if *nothing*
arrived for its whole width, so at `1h` a 20-minute outage leaves `empty_bins: 0`
while `silent_minutes` still reports 20. If you show `longest_gap_bins` as a
duration, multiply it by the bin width first — prefer `silent_minutes` at anything
coarser than a minute.

**Do not compare rates across collections, and do not derive a percentage.** The
API does not know any host's configured poll interval, so there is no "expected"
value to score against and no uptime percentage can be computed from this data.
`per_minute` is comparable across bin widths and across time *for one collection*.
Two collections on one axis need a note that they poll at different cadences.

**Retention is 30 days.** Older ranges return zeros, not an error, so a date
picker that reaches further back will show a flat silent line rather than failing.

## Worked example

```ts
// app/(dashboard)/collections/[pid]/health/actions.ts
'use server';

type Bin = '1m' | '10m' | '30m' | '1h' | '6h' | '1d';
type Window = '1h' | '6h' | '24h' | '7d' | '30d';

export async function getCollectionHealth(pid: string, bin: Bin, window: Window) {
  const res = await fetch(
    `${process.env.API_BASE_URL}/api/v1/collections/${pid}/health?bin=${bin}&window=${window}`,
    { headers: { Authorization: `Bearer ${await getToken()}` }, cache: 'no-store' },
  );

  if (res.status === 422) throw new Error('Range too fine for that window');
  if (!res.ok) throw new Error(`Health request failed: ${res.status}`);

  const { data } = (await res.json()) as { data: CollectionHealth };
  return data;
}
```

```tsx
const health = await getCollectionHealth(pid, bin, window);
const { series, summary } = health;

// The chart. y is per_minute at every bin width, so the axis is stable when the
// user changes `bin` - no re-scaling, no re-fitting the domain.
const binWidth = Math.max(...series.a.map((p) => p.minutes)); // nominal width
const points = series.a
  .filter((p) => p.per_minute !== null)
  .map((p) => ({
    x: new Date(p.bin),
    y: p.per_minute as number,
    // tooltip detail
    total: p.n,
    silent: p.silent_minutes,
    provisional: p.minutes < binWidth, // the in-progress final bin
  }));

// The badge.
const { per_minute, silent_minutes, last_ping_at } = summary.a;

const state =
  last_ping_at === null       ? 'never polled'
  : per_minute === 0          ? 'silent'
  : silent_minutes > 0        ? 'intermittent'
  :                             'healthy';
```

Y-axis label: **"polls / min"**. It is correct for every value of `bin`, which is
the point.
