# Collection concept scan

The collection details endpoint (`GET /api/v1/collections/{pid}/details`) reports the concepts a collection holds from its **last successful concept scan**.

## What counts as successful

A `code.distribution` result file is successful when its status is `done`. `Collection::latestSuccessfulConceptResultFile` returns the newest such file, ordered by `updated_at` then `id`.

The status is decided once, at ingest. `ProcessDistributionFile` marks a `code.distribution` file `failed` with the error `No concepts found in file` when none of its rows carries a concept id greater than 0. That covers a header-only file from BUNNY, and a file whose codes are all unmapped. `rows_processed` still records how many rows were read. The views are not refreshed for a failed file.

Other distribution files, such as `demographics.distribution`, are not held to this rule.

The file is marked `done` before the two view refresh jobs are dispatched, so the refresh always sees it as successful.

## Existing files

Files ingested before this rule are `done` even when empty. The migration `2026_11_24_100000_fail_existing_concept_files_without_concepts` marks each such file `failed` with the same error. A row belongs to a file through `result_file_id`, or through `task_id` when `result_file_id` is NULL, because that column was added later as nullable with no backfill. The migration has no `down`: it cannot tell which files it changed.

Until the migration has run, an old empty scan can still win as the latest `done` file.

## Response shape

| Key | Meaning |
|-----|---------|
| `latest_successful_concept_result_file` | The file above, with its `task`. `null` when no scan has succeeded. |
| `nconcepts` | Distinct `concept_id > 0` in that file's task. `0` when there is no successful scan. |
| `concept_counts_by_category` | The same count grouped by category. |
| `result_files` | Every result file for the collection, newest first, each with its `task` and, for a failed file, its `error`. The newest `code.distribution` entry is the latest attempt. |

The details page compares the latest attempt with the successful file and warns when they differ, for example when the newest scan failed because it contained no concepts.

## Shared consumers

`RefreshLatestDistributionsView` and `RefreshDistributionConceptsView` build from the same relation, so the term directory and vocabulary drift report use the last scan that returned concepts.

## Not used

`Collection::latestSuccessfulConceptTask` selects tasks that have a `result` row. An empty scan still has one, so it cannot tell a good scan from an empty one.
