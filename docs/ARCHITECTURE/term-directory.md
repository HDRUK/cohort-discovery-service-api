# Term Directory

`GET /api/v1/term-directory` lists OMOP concepts with how many people and how many collections report them. The logic lives in `app/Services/TermDirectory/TermDirectoryService.php`; the controller only calls it, logs the view and returns the response.

## Data source

The endpoint reads `latest_distributions_materialised` (model `LatestDistribution`), a snapshot of the latest `code.distribution` result per collection. `RefreshLatestDistributionsView` rebuilds it when new distributions arrive and on deploy. The snapshot holds one row per collection and concept; it stores no collection names.

## Scoping

Every query is limited to the caller's visible collections (`Collection::visibleToUser`), optionally narrowed by `collection_pid[]`. A requested pid outside the visible set is dropped, so the filter can only narrow access. Rows with a zero count are ignored.

## Two-phase query

Aggregating every concept is expensive, so the work is split:

- **Phase A** picks the page: `SUM(count)` grouped by `concept_id, domain_id`, sorted and limited to one page.
- **Phase B** computes the costly aggregates for only the concepts on that page (about 25): `ncollections`, `collection_ids`, `reported_domains` and the concept name. The index `ldm_concept_domain_collection_count_idx` narrows it to those concepts' rows.

## `ncollections` and `collection_names`

Each row returns:

| Field | Meaning |
| --- | --- |
| `ncollections` | Number of visible collections reporting the concept with a non-zero count. |
| `collection_names` | Names of those same collections, sorted alphabetically (case-insensitive). |

How the names are produced without a join or an extra query:

1. The visibility lookup already runs once per request. It plucks `id => name`, so the ids scope the queries and the names are kept for later.
2. Phase B adds `JSON_ARRAYAGG(collection_id) AS collection_ids`. `collection_id` is already read for `ncollections`, so no extra rows or columns are read.
3. PHP maps the ids to names from step 1, de-duplicates and sorts them.

Design notes:

- **`JSON_ARRAYAGG`, not `GROUP_CONCAT`.** `GROUP_CONCAT` silently truncates at `group_concat_max_len` (1024 bytes by default), which would drop names while `ncollections` stayed correct. `JSON_ARRAYAGG` has no `DISTINCT` on MySQL 8.0, so duplicates are removed in PHP.
- **Names are read at request time**, not stored in the snapshot, so a renamed collection shows straight away.
- **Access control.** A name can only come from the caller's scoped `id => name` map, so `collection_names` can never include a collection that `ncollections` does not count or that the caller cannot see.

## Tests

`tests/Feature/TermDirectoryControllerTest.php` covers the names being alphabetical, excluding zero-count and filtered-out collections, and being limited to the caller's visible collections.
