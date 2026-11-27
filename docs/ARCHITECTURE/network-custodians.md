# Network custodians

`GET /api/v1/custodian_networks/{id}/custodians` lists the custodians in one network, paged and searchable. The web's admin networks tab uses it for the table of the selected network's custodians.

## Query parameters

| Parameter | Effect |
|---|---|
| `name[]` | Search by custodian name (`Custodian::$searchableColumns`). |
| `sort` | Sort by a column in `Custodian::$sortableColumns`. |
| `page`, `per_page` | Laravel pagination. `per_page` goes through `resolvePerPage()`: default `config('api.per_page')`, capped at 100. |

## Response

The standard paginator inside `data`, the same shape as `GET /custodians/{custodianPid}/collections`: `data.data` holds the custodians, with `total`, `per_page`, `current_page` and `last_page` beside it.

An unknown network id fails validation with 422, as `show` does: `ModelBackedRequest` treats a GET with a route parameter as the `show` context, so the `exists:custodian_networks,id` rule applies.

## Why a separate endpoint

- `GET /custodian_networks` and `GET /custodian_networks/{id}` embed every custodian of a network, with no paging or search.
- `GET /custodians` searches by name but can't filter by network, and adding paging to it would change an existing v1 response.

The query goes through the network's `custodians()` has-many-through relation. Search (`name`) and sort (`name`) can't be ambiguous across the join, because `custodian_network_has_custodians` has no `name` column. Each custodian in the response carries `laravel_through_key` (the network id), as in `show`.

It sits with the other read routes for networks, so any signed-in user can call it, matching `index` and `show`.
