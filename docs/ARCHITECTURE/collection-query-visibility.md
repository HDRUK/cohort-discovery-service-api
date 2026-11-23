# Collection visibility on query submission

A user can only query the collections they can see. The rule that decides which collections those are is `Collection::scopeVisibleToUser` (`app/Models/Collection.php`). The same rule backs `GET /v1/user/collections`, so the API never queues a task for a collection the user wasn't shown.

## Who sees what

| User | Visible collections |
|---|---|
| Admin | All of them |
| Workgroup member | Collections linked to one of their workgroups (`workgroup_has_collection`) that are in the `active` state |
| Custodian member | Every collection of that custodian (`custodian_has_users`), whatever its state |

A user who is both a workgroup member and a custodian member sees both sets.

## Where it is enforced

| Endpoint | Code | Behaviour |
|---|---|---|
| `POST /v1/queries` | `QuerySubmissionService::handle` | Returns 403 if any pid in `collection_filter` is a collection the user can't see. Nothing is created. An empty filter creates tasks for every visible collection. |
| `GET /v1/query/re-run/{id\|pid}` | `QueryController@duplicateAndReRun`, then `QuerySubmissionService::handle` | Returns 404 for an unknown query and 403 unless the user owns it (`QueryPolicy::view`; admins can re-run any query). Re-runs against the original query's collections and returns 403 if any of them is no longer visible. |
| `GET /v1/task/re-run/{pid}` | `TaskController@cloneTask` | Returns 404 for an unknown task, and 403 unless the user owns the task's query (`QueryPolicy::update`) and can see the task's collection. |

`QuerySubmissionService` checks visibility before opening its transaction and throws `AuthorizationException`. The controllers turn that into a 403. The check has to happen outside the transaction because `handle` swallows every exception raised inside it.

## Edge cases

- **Unknown pids** in `collection_filter` are still ignored, as they were before. Only collections that exist but aren't visible are refused.
- **Soft-deleted collections** count as unknown. Both the existence check and the visibility check exclude them.
- **Losing access.** A re-run is checked against the user's access at re-run time, not at the time of the original submission.
- **BUNNY polling** (`/v1/task/nextjob/{collectionPid}`) doesn't use these checks. Collection hosts authenticate as the collection, not as a user.

Task re-run uses `QueryPolicy::update` rather than `TaskPolicy::view` because the new task is added to the original query. `TaskPolicy::view` also lets custodian members see tasks on their collections, and they shouldn't be able to add tasks to another user's query.

## Not covered here

- `cloneTask` doesn't re-check the location and death feature flags.
- `POST /v1/collection/{pid}/distributions/run-manually` (`DistributionController::manuallyTriggeredRun`) creates a task for any collection without checking visibility.
