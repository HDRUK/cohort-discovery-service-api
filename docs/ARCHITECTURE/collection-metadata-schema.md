# Collection metadata schema (spike DP-0000)

Status: spike. The web side and the package verdict are written up in `project-daphne-web/docs/ARCHITECTURE/collection-metadata-schema.md`.

## Configuration

`config/collection_schema.php`:

- `active`: the deployment's schema, from `COLLECTION_SCHEMA` (default `CDS@1.0.0`).
- `schemas`: each known schema's file and the dotted paths that map to the `name`, `description` and `url` columns.

Schema files live in `resources/schemas/<Model>/<version>/schema.json` (JSON Schema 2020-12). `App\Services\Collections\CollectionSchema` loads the active one and validates with `opis/json-schema`, collecting every error rather than stopping at the first.

## Storage

Migration `2026_11_18_100000_add_metadata_to_collections_table` adds `metadata` (JSON) and `metadata_schema` (string) to `collections`, and backfills each row from its `name`, `description` and `url`. `metadata` is hidden from ordinary collection responses.

## Sync rule

- `PUT /v1/collections/{id|pid}/metadata` `{ metadata }`: `update` policy; validates against the schema (422, errors keyed by dotted path); stores the document and copies the mapped fields into the columns.
- `Collection::booted()` `saving` hook: when `name`, `description` or `url` change, or the document is empty, writes them into the document and sets `metadata_schema`. It does not validate.

## Reads

- `GET /v1/collection-schema` returns `{ id, schema }`.
- `GET /v1/collections/{id|pid}/metadata` returns `id, pid, name, description, url, metadata, metadata_schema` for a collection in `Collection::visibleToUser`, otherwise 404.

## Tests

`tests/Feature/CollectionMetadataTest.php`. It calls `enableObservers()` because `TestCase` turns model events off, and `Http::fake()` because collection observers call collection hosts.

## Moving to schemata-2

See the web doc. In short: author `models/CDS/v1_0_0` in `Gateway2.0/schemata-2`, build it, then point `config/collection_schema.php` at the published `schema.json` or call TRASER's `/get/schema` and `/validate`.
