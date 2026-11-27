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

The query selects custodians whose id is in the network's `custodian_network_has_custodians` rows, rather than going through the `custodians()` has-many-through relation, so search and sort run against the `custodians` table alone with no joined, ambiguous columns.

It sits with the other read routes for networks, so any signed-in user can call it, matching `index` and `show`.
