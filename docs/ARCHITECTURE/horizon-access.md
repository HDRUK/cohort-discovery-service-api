# Horizon access in production

Horizon is gated by the `viewHorizon` gate in `app/Providers/HorizonServiceProvider.php`.

- Outside production the gate allows everyone.
- In production, visit `/horizon?key=<HORIZON_SECRET>`. A correct key stores a hash of the secret in the session, so the dashboard's own `/horizon/api/...` calls (which do not carry the query string) are authorised too.
- Changing or unsetting `HORIZON_SECRET` invalidates existing sessions, because the stored hash no longer matches.
- If `HORIZON_SECRET` is empty, nobody gets in via the key.

The admin-role fallback in the gate is inert: Horizon runs under the `web` middleware, so API bearer tokens never authenticate a user there.
