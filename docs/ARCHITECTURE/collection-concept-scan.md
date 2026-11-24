# Collection concept scan

The collection details endpoint (`GET /api/v1/collections/{pid}/details`) reports the concepts a collection holds from its **last successful concept scan**.

## What counts as successful

A `code.distribution` result file is successful when its status is `done`. `Collection::latestSuccessfulConceptResultFile` returns the newest such file, ordered by `updated_at` then `id`.

The status is decided once, at ingest. `ProcessDistributionFile` marks a `code.distribution` file `failed` with the error `No concepts found in file` when none of its rows carries a concept id greater than 0. That covers a header-only file from BUNNY, and a file whose codes are all unmapped. `rows_processed` still records how many rows were read.

Other distribution files, such as `demographics.distribution`, are not held to this rule.

For a failed file no view refresh is dispatched. For a done file the file is marked `done` before the two refresh jobs are dispatched, because they select by status.

## Files ingested before this rule

Empty `code.distribution` files ingested earlier are still `done`. The deployment step `2026_11_24_000001_fail_concept_files_without_concepts`, run by `deploy:run`, dispatches `FailConceptFilesWithoutConcepts` onto the queue (Horizon). The step itself returns immediately. The job visits the `done` `code.distribution` files one at a time, checks whether its task has any distribution row with a `concept_id` above 0 (the same task-based rule the details endpoint uses to count concepts), and marks any with no concepts `failed` with the same error, leaving `updated_at` unchanged. Every file is its own small statement, so there is no long query or transaction to hit a timeout. When it has been through every file it dispatches the two view refreshes, so the term directory picks up each collection's last good scan.

The job is idempotent and resumable, because a file it has failed is no longer `done`, so it can be run again by hand with `FailConceptFilesWithoutConcepts::dispatch()`. If the number of files ever made one run too long, the next step would be one job per file in a batch (`job_batches` already exists). A deployment step is recorded as run once the job is dispatched, so a job that later fails is not retried by the next deploy. Until the job has finished, an old empty scan can still win as the latest `done` file.

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
