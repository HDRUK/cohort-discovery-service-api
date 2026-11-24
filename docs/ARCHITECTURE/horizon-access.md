# Horizon access in production

Horizon is gated by the `viewHorizon` gate in `app/Providers/HorizonServiceProvider.php`. `App\Http\Middleware\ChallengeHorizonBasicAuth` runs on every Horizon route (`config/horizon.php`) and turns a denial into an HTTP Basic challenge.

- Outside production the gate allows everyone.
- In production, open `/horizon`. The browser asks for credentials: enter any username and `HORIZON_SECRET` as the password.
- The browser resends those credentials on the dashboard's own `/horizon/api/...` calls, so nothing is stored in the session.
- The secret never appears in a URL, so it stays out of request logs, the `request_audits` table and browser history. `?key=` is no longer accepted.
- If `HORIZON_SECRET` is empty, nobody gets in.
- There is no logout: the browser keeps the credentials until it is closed. Changing `HORIZON_SECRET` locks everyone out on their next request.
- Nothing throttles wrong guesses, so `HORIZON_SECRET` must be long and random.
- Horizon still runs under the `web` middleware group, so its POST and DELETE routes keep CSRF protection.
