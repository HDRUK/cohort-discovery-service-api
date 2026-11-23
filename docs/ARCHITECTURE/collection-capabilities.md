# Collection capabilities

Some behaviour depends on the collection a query runs against, not on who is asking. For example, the query builder limits rules to what BUNNY v1 can run, which only matters for collections whose BUNNY is v1. Model these as **collection capabilities**, not as feature flags.

This is a design for when one is needed. Only `location_enabled` and `death_enabled` exist today.

## The model

**Effective behaviour = feature flag AND collection capability.**

- The **feature flag** is an admin's decision about who gets a feature: rolling it out, or switching it off. It can be overridden per user or workgroup (see `scoped-feature-flags.md`).
- The **collection capability** is a fact about what one collection can do.

Location already works this way. `QuerySubmissionService::handle` fails a location query's task on a collection unless the user has `query-builder-use-location` **and** the collection has `location_enabled`. The query's other collections still run.

## Why not a feature flag scoped to collections

Pennant could store `Feature::for($collection)` rows, and `FeatureResolver` could read them. It isn't used for this because:

- **A query has no single answer.** A query targets several collections at once, so a per-collection flag has no one value. The way to combine them depends on the behaviour:
  - BUNNY v1 constraints apply if **any** selected collection is v1;
  - location runs where it's supported and fails elsewhere.

  A generic resolver can't know which rule applies.
- **Automatic values would clash with admin choices.** A value derived from metadata would be rewritten on every ingestion. That would overwrite an admin's choice, or an admin's row would block a real change. Nothing would record where a value came from.
- **Flags are read in the wrong place.** Flags resolve for the signed-in user, through `GET /api/v1/features` and the web app's `useFeatures`. Capabilities are needed per collection: in the query builder's collection panel and at submission.

## Storing a capability

- **One capability:** use a boolean or string column on `collections`, as `location_enabled` and `death_enabled` are.
- **Several capabilities, some set automatically:** use a `collection_capabilities` table with these columns:

| Column | Holds |
|---|---|
| `collection_id` | The collection |
| `capability` | The capability's name |
| `value` | Its value |
| `source` | `metadata` or `admin` |
| `updated_at` | When it last changed |

How the sources work together:
- An `admin` row wins over a `metadata` row, so an admin can correct a wrong value.
- Ingestion only writes `metadata` rows, so it never overwrites an admin's correction.
- If a capability is unknown, assume the most restricted behaviour.

## Setting capabilities automatically

A capability can only be derived from what BUNNY sends:

- **The metadata file.** `App\Jobs\ProcessMetadataFile` reads `biobank, protocol, os, bclink, datamodel, rounding, threshold` into `collection_metadata`. A new column, such as a BUNNY version, could be derived into capabilities after `CollectionMetadata::create`. It updates only when a distribution job runs.
- **Polling.** `TaskController@nextJob` runs `CollectionPollRecorder::record` on every poll, so a version header could be recorded there. It would stay current after a BUNNY upgrade. BUNNY sends no version or worker id today; the code falls back to the request IP.

Either route needs a change on the BUNNY side first.

## Using a capability

- **Submission:** add a reason to the location and death checks in `QuerySubmissionService::handle`. Use `taskFailureRecorder->failWithReasons`, so only the collections that can't run the query fail and the user sees why.
- **Query builder:** use the collections selected in `CollectionsRightPanel` to apply constraints and show hints, as is done for location and death.

## Worked example: `constrain-for-bunny-v1`

Today this is a global flag, and only the web app reads it. When it is on, the query builder allows an age constraint **or** a time constraint on a rule, not both, and makes timeframes one-directional. The API doesn't check it.

As a capability it would work like this:
1. BUNNY reports its version, through the metadata file or a polling header.
2. A collection capability records whether that version can run combined age and time constraints. An unknown version counts as v1.
3. The query builder applies the v1 constraints when the flag is on **or** any selected collection can't run combined constraints.
4. Submission fails the task on any collection that can't run the query.
5. Once every BUNNY reports its version, the flag is redundant. Remove it from `config/features.php`, and `FeatureSeeder` purges it.

Open questions:
- Exactly what can BUNNY v2+ run that v1 can't?
- Will BUNNY send a version, and by which route?

Until a capability exists, a flag that really describes what a collection can do should be listed in `global_only`. Otherwise a user or workgroup override could allow queries that the collection can't run.
