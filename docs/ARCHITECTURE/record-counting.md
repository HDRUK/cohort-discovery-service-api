# Record counting (DP-1293)

An availability query normally returns one number: how many people match. With record counting, BUNNY can also count records in another OMOP table. For example, a query for people with a specimen of type X can also report how many matching specimens there are.

Only the Specimen table is supported. This needs BUNNY's `spike/DP-1293` branch, and the BUNNY deployment needs `OMOP_SPECIMEN_ENABLED=true`.

## Flag

`query-record-counting` is defined in `config/features.php` and is off by default. It is [global-only](scoped-feature-flags.md#global-only-flags), because it is only read while BUNNY is polling, when there is no user.

## When the API asks for a record count

`App\Services\QueryContext\Contexts\Bunny\RecordCountUnits::forCohort()` looks at the translated cohort. It returns `['Specimen']` only when both of these are true:

- the flag is on, and
- the cohort contains **at least one** rule with `varcat` `Specimen` and `oper` `=`. Rules inside nested groups count too.

This matches BUNNY's validator. BUNNY rejects a task that asks to count specimens but has no Specimen inclusion rule. A query with no specimen rule, or with only exclusion (`!=`) specimen rules, is not asked for a count, so it runs as a normal person count.

## What BUNNY counts

The count is the specimen records that belong to people in the cohort and match **any** of the query's Specimen inclusion rules. Each rule's own constraints, such as age at the time or a time window, apply. A specimen that matches two rules is counted once. Exclusion rules don't add to the count, but they still shape the cohort.

If BUNNY is asked for a count but its specimen support is off (`OMOP_SPECIMEN_ENABLED` is false, or `Specimen` is not in `OMOP_COUNTABLE_VARCATS`), the task fails for that collection. Only turn the flag on when every collection's BUNNY supports specimen counting.

## Payload

When counting applies, `TaskController@nextJob` adds one top-level field to the type A task:

```json
{
  "task_id": 1,
  "uuid": "…",
  "cohort": { "groups_oper": "OR", "groups": [ … ] },
  "count_units": ["Specimen"],
  "…": "…"
}
```

When it does not apply, the field is left out, so the payload is exactly what it was before. BUNNY versions that don't know about the field ignore it.

## Result

BUNNY then returns a count for each unit instead of a single integer:

```json
{ "status": "ok", "queryResult": { "count": { "person": 40, "specimen": 150 }, "datasetCount": 0 } }
```

`TaskController@receiveResult` accepts either form, whatever the flag is currently set to:

| `queryResult.count` | `results.count` | `results.metadata.record_counts` |
|---|---|---|
| `42` | `42` | not set |
| `{"person": 40, "specimen": 150}` | `40` | `{"specimen": 150}` |
| an object with no `person` key, or with a non-numeric value | rejected with 400 | |

`results.count` always holds the person count. Code that reads it, such as the frontend, regression tests and Beacon, works without changes.

BUNNY applies disclosure control to the record count. The count is rounded, and it is 0 when the number of people behind it is below the low-number threshold. The API stores the value exactly as it receives it.

## Reading the counts

`QueryController@index` and `@show` already return `tasks[].result.metadata`. The web app can read the specimen count from `result.metadata.record_counts.specimen`. When the key is missing, no record count was requested.
