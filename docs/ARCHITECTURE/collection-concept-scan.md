# Collection concept scan

The collection details endpoint (`GET /api/v1/collections/{pid}/details`) reports the concepts a collection holds from its **last successful concept scan**.

## What counts as successful

`Collection::latestSuccessfulConceptResultFile` returns the newest `code.distribution` result file that is:

- `status = done`, and
- backed by at least one `distributions` row with `concept_id > 0`. A row belongs to the file through `result_file_id`, or through `task_id` when `result_file_id` is NULL.

A file can be `done` and still empty. `ProcessDistributionFile` marks a file `done` once it has been read, even when BUNNY returned a header-only file or every row was skipped. Such a file is not a successful scan, so it never replaces an earlier scan that had concepts.

`distributions.result_file_id` was added later as a nullable column with no backfill, so rows written before it carry only `task_id`. Matching on `result_file_id` alone would make those scans look empty, which is why the NULL case falls back to `task_id`.

`result_files.rows_processed` is deliberately not used for this rule. The column is `NOT NULL DEFAULT 0`, so files that predate it or were inserted without it would read as empty.

## Response shape

| Key | Meaning |
|-----|---------|
| `latest_successful_concept_result_file` | The file above, with its `task`. `null` when no scan has returned concepts. |
| `nconcepts` | Distinct `concept_id > 0` in that file's task. `0` when there is no successful scan. |
| `concept_counts_by_category` | The same count grouped by category. |
| `result_files` | Every result file for the collection, newest first, each with its `task`. The newest `code.distribution` entry is the latest attempt. |

The details page compares the latest attempt with the successful file and warns when they differ, for example when the newest scan returned 0 rows.

## Shared consumers

`RefreshLatestDistributionsView` and `RefreshDistributionConceptsView` build from the same relation, so the term directory and vocabulary drift report also skip empty scans and use the last scan that returned concepts.

## Not used

`Collection::latestSuccessfulConceptTask` selects tasks that have a `result` row. An empty scan still has one, so it cannot tell a good scan from an empty one.
