# Summary
- Blocks a task when its query places a location filter in the **demographics block** but the target collection does not expose the location table (`location_enabled = false`).
- Previously the location-blocking check (DP-1029) only inspected clinical rules for a `Location`-category OMOP concept. A query whose location was expressed solely as a demographic geo-radius (`demographics.location = {lat, lon, radius}`, the DP-1068 `GEO_RADIUS` shape) slipped through unblocked and would be sent to collections that cannot answer it.
- Now such tasks fail up front via the existing `TaskFailureRecorder`, surfacing the same `"Location data table missing"` reason on the Collection Results page as a clinical location query does.

# Type Of Change
- [x] Bug fix
- [ ] Feature
- [ ] Refactor
- [ ] Docs
- [ ] Test
- [ ] Chore

# Testing
- [x] `composer run test` (`--filter=QueryBlockingTest`, 6 passed / 25 assertions)
- [x] `composer run lint` (`lint:dirty`, passed)
- [ ] `npm run lint:workflows` (not applicable — no workflow changes)

# Screenshots / Evidence (if applicable)
- 

# Rollout / Risk
- Impacted areas:
  - `QueryDefinitionInspector::usesDemographicLocation()` — new detector, matches the exact numeric `{lat, lon, radius}` shape that `BunnyQueryContext::makeGeoRadiusRule()` treats as a real filter. The legacy region-code array shape emits no BUNNY rule and is intentionally **not** flagged, so it stays unblocked.
  - `QuerySubmissionService::handle()` — `$usesLocation` now ORs in the demographic-location check; all downstream blocking logic (feature gate, `location_enabled` guard, `TaskFailureRecorder`) is reused unchanged.
- Behaviour note: this reuses the same `query-builder-use-location` feature gate. With that flag off, demographic-location queries are now blocked everywhere, consistent with how clinical `Location` concepts already behave.
- Rollback plan: revert the commit; no schema or data changes.

# Notes
- Stacks on top of DP-1068 (demographic → `GEO_RADIUS` translation); PR is targeted at the `feat/DP-1068` branch and will auto-retarget to `dev` once that merges.
