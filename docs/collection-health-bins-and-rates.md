# Change summary: any bin width, always a per-minute rate

Follow-up to [collection-health.md](collection-health.md), which describes the
endpoint as a whole. This is the write-up of one change with two halves:

1. `bin` accepts an arbitrary width, not just the five named units.
2. Every bin now carries an **average pings per minute** rate, so the numbers mean
   the same thing at whichever width is asked for.

They ship together because either alone is half-useful: custom widths without
normalisation just give you more ways to rescale the y-axis, and normalisation
without custom widths leaves a 60× gap between `minute` and `hour`.

## The problem

`bin` was the `HealthBin` enum and nothing else — `minute`, `hour`, `day`,
`week`, `month` — and the series value was `n`, the raw sum of pings in the bin.
Two consequences, both felt as soon as anyone tried to build a chart with a bin
picker on it:

- **No resolution between a minute and an hour.** A minute bin over an hour is a
  60-point chart; the next step up collapses that hour to a single point. "Six
  hours at ten-minute resolution" was not expressible.
- **Changing the bin rescaled the chart.** The same host at the same cadence
  reads ~12 at `minute` and ~706 at `hour`, because `n` is a sum. The bin control
  was really a scale control, and no two views could be compared.

## What it does now

**`bin` takes a multiple of a unit** as well as a named unit: `10m`, `20m`,
`30m`, `6h`, `2d`, `4w`. The multiplier is `[1-9]\d{0,4}`, so 1–99999 of `m`,
`h`, `d` or `w`.

**Every series point and every summary carries `per_minute`** — always, at every
width, including `bin=minute` where it equals `n`. Alongside it, `minutes` (how
many minutes the bin covers) and `silent_minutes` (how many of those had zero
pings). `n` stays exactly as it was.

So the two controls are now independent in the way a user expects: **bin is
resolution, range is span, and neither changes what the y-axis means.**

## How it works

### Bin widths — `app/Support/HealthBinWidth.php`

A value object holding a unit plus a multiplier, sitting in front of the enum and
owning the vocabulary. It exposes what the read path needs — `sqlExpression()`,
`floor()`, `next()`, `minutesIn()`, `countBetween()` — so `CollectionHealthService`
only changed the type it resolves.

**A multiple of one delegates to the enum.** `1h` and `hour` resolve to the same
object and take the enum's existing code path, so Monday-start weeks and
1st-of-month months are untouched and still pinned by their original tests. Only
widths greater than one take new code.

**Wider widths snap to an absolute grid anchored at the epoch**, computed with
`TIMESTAMPDIFF(MINUTE, …)` in SQL and integer division on the timestamp in PHP:

```sql
DATE_ADD('1970-01-01 00:00:00',
  INTERVAL FLOOR(TIMESTAMPDIFF(MINUTE, '1970-01-01 00:00:00', bucket_minute) / 10) * 10 MINUTE)
```

Calendar arithmetic on the stored value rather than `UNIX_TIMESTAMP`, so the
result does not depend on the connection time zone. Anchoring on the epoch rather
than on the request means a `10m` bin always starts at `:00`, `:10`, `:20` — two
overlapping requests agree on boundaries, which is what makes a series cacheable
and comparable across requests. Week multiples anchor on `1969-12-29`, the Monday
before the epoch, so `2w` bins stay Monday-aligned like `week`. The trade: a width
that does not divide its next unit up drifts predictably instead of tracking the
calendar — `7d` bins start on Thursdays, because the epoch was one. Use `1w` for
Monday-aligned weeks.

**`month` has no multiple form.** It is the only variable-width unit, so `2mo` is
not a fixed number of minutes; it is rejected with a 422. `mo` is not a suffix at
all — `m` is minutes, consistent with `window`.

### Rates — `app/Services/Collections/CollectionHealthService.php`

`per_minute` is `n / minutes`, to 3dp. The interesting part is the denominator.

**Complete bins divide by their nominal width**, from `HealthBinWidth::minutesIn()`,
which is derived from `next()` so months come out as 28–31 days without a special
case.

**The in-progress bin divides by the minutes elapsed so far.** The final bin of
any range is always partial, and dividing it by the full width would drag a
healthy host's last point toward zero and read as an outage:

```
now = 10:05:30, host polling 10/min, hour bins

elapsed:  n=60 / 6 min  = 10.0/min   <- flat line, correct
nominal:  n=60 / 60 min =  1.0/min   <- looks like a 90% drop
```

Elapsed minutes are rounded up with a floor of 1, because storage is
minute-granular: a bin ten seconds old has exactly one minute bucket in play, so
`n / 1` is the honest rate, there is no divide-by-zero, and no spike from a
fractional divisor. The imprecision is bounded by the in-progress minute.

**A bin wholly in the future** — only reachable with a `from`/`to` range past now —
gets `minutes: 0` and `per_minute: null`, keeping "no data can exist yet" distinct
from "the host was silent", and is excluded from the summary denominator.

**`summary.per_minute` is minute-weighted**: `pings / minutes` across the range,
not the mean of the bin rates, so a six-minute partial bin cannot count as much as
a sixty-minute complete one.

### Silent minutes — free from the existing query

At coarse widths `empty_bins` stops detecting outages: an hour bin is only "empty"
if the whole hour was silent, so a 20-minute gap vanishes. A bucket row exists
only for a minute that actually had a ping (see `CollectionPingRecorder::record`),
so `COUNT(*)` within the existing `GROUP BY` is minutes-with-pings, and
`minutes - COUNT(*)` is silence. No new query, no new column, no new index.

That gives the pair a UI needs to read a dip correctly: **rate down with
`silent_minutes` up is an outage; rate down with `silent_minutes: 0` is a slower
cadence.**

## Full resolution range

| `bin` | Bin start | |
|---|---|---|
| `minute` / `1m` | every minute | base storage resolution |
| `10m`, `30m`, `Nm` | epoch grid, so `:00`, `:10`, … | new |
| `hour` / `1h` | top of the hour | |
| `6h`, `Nh` | epoch grid | new |
| `day` / `1d` | midnight UTC | |
| `2d`, `Nd` | epoch grid (`7d` starts Thursdays) | new |
| `week` / `1w` | Monday | |
| `4w`, `Nw` | Monday, epoch-Monday grid | new |
| `month` | 1st of the month | no multiple form |

Both range modes work with every width. `window` is a duration back from now and
returns exactly that many bins ending at the current partial bin —
`?bin=10m&window=1h` is 6 bins. `from`/`to` is an explicit span, inclusive at both
ends, with `from` floored to the enclosing bin. `to` in the response stays
exclusive.

Practical resolutions inside the default 2,000-bin ceiling:

| Range | Widths that fit |
|---|---|
| 1 hour | `minute` (60) and anything coarser |
| 24 hours | `minute` (1,440), `10m` (144), `hour` (24) |
| 7 days | `10m` (1,008), `30m` (336), `hour` (168) |
| 14 days | `15m` (1,344), `30m` (672) — `10m` needs 2,016, just over |
| 30 days (full retention) | `30m` (1,440), `hour` (720), `6h` (120), `day` (30) |

Over-wide requests are still refused with a 422 under `errors.bin`, naming the bin
count it would have needed, rather than served as a 100k-point payload.

## Compatibility

Additive. Same route, same auth, same defaults, same envelope, same ceiling. `n`,
`pings`, `bins`, `empty_bins`, `longest_gap_bins` and `last_ping_at` all keep
their existing meanings and values; `per_minute`, `minutes` and `silent_minutes`
are new keys alongside them. The only behavioural difference on a previously
valid request is the four extra keys. The 422 message for an unrecognised `bin`
is reworded, since the vocabulary it has to describe is larger.

Bin parsing also moved out of the controller: `bin` is validated there only for
shape (`string`, `max:16`), and `HealthBinWidth::parse()` throws the
`ValidationException` for anything it cannot resolve. Same 422, same `errors.bin`
key, one source of truth for what a valid width is.

## Evidence

`tests/Feature/Api/V1/CollectionHealthControllerTest.php` — 28 tests, 156
assertions, all passing. Thirteen are new.

Bin widths:

- `it_bins_at_a_custom_ten_minute_width` — off-grid `from`/`to`, asserts bins snap
  to `:00`/`:10` rather than to `from`, and that counts land in the right bins
  (which is what proves the grid SQL and the PHP floor agree — if they disagreed
  the series would read as all zeros).
- `it_fits_a_one_hour_window_into_six_ten_minute_bins` — window arithmetic.
- `it_normalises_a_multiple_of_one_to_the_named_unit` — `1h` echoes as `hour`.
- `it_lines_up_sql_and_php_boundaries_for_multi_week_bins` — every `2w` bin start
  is a Monday.
- `it_rejects_bin_widths_it_cannot_express_in_minutes` — `2mo`, `0m`, `-5m`, `90s`,
  `m` all 422 under `errors.bin`.
- `it_rejects_a_custom_bin_width_needing_more_bins_than_the_ceiling_allows`.

Rates:

- `it_reports_the_same_rate_at_every_bin_width` — one fixture queried at `minute`,
  `10m` and `hour` over the same range; identical `per_minute`, `pings`, `minutes`
  and `silent_minutes` in all three. This is the actual requirement, so it gets its
  own test.
- `it_reports_a_per_minute_rate_at_minute_resolution` — the rate is present even
  where it equals `n`.
- `it_normalises_a_complete_hour_bin_to_a_per_minute_rate` — 12 pings over a
  complete hour is `0.2`, with `silent_minutes: 58`.
- `it_divides_the_in_progress_bin_by_the_minutes_elapsed_so_far` — clock pinned to
  `10:05:30`; the hour bin's `minutes` is 6, not 60, so the rate is `10`, not `1`.
- `it_counts_silent_minutes_inside_a_wide_bin` — pings in 3 minutes of a `10m` bin
  gives `silent_minutes: 7` while `empty_bins` stays 0.
- `it_reports_a_null_rate_for_bins_wholly_in_the_future` — `minutes: 0`,
  `per_minute: null`, excluded from the summary.
- `it_weights_the_summary_average_by_minutes_rather_than_by_bin` — one complete and
  one partial bin: `1.818`, not the `5.5` that averaging the bin rates would give.

The 15 pre-existing tests are unchanged, apart from `it_rejects_an_unknown_bin`
gaining an assertion that the 422 still reports under `errors.bin`.

`composer run lint` and `composer run phpstan` clean. `php artisan
l5-swagger:generate` re-run — this also adds the health endpoint to
`storage/api-docs/api-docs.json` for the first time, as it had not been
regenerated since the endpoint was added.

## Files

| Concern | File |
|---|---|
| Bin width parsing, grid SQL, PHP floor, `minutesIn` | `app/Support/HealthBinWidth.php` (new) |
| Bin units, calendar boundaries | `app/Enums/HealthBin.php` (unchanged) |
| Aggregation, rates, coverage, silent minutes | `app/Services/Collections/CollectionHealthService.php` |
| Validation and OpenAPI | `app/Http/Controllers/Api/V1/CollectionHealthController.php` |
| Tests | `tests/Feature/Api/V1/CollectionHealthControllerTest.php` |
| Reference docs | `docs/collection-health.md` |
| Frontend hand-off | `docs/collection-health-frontend.md` |
