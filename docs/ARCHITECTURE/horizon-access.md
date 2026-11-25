# Horizon access

Horizon is protected by HTTP Basic auth using a dedicated service account named `horizon`. `App\Http\Middleware\AuthenticateHorizonServiceAccount` runs on every Horizon route (`config/horizon.php`) and turns a missing or wrong credential into a Basic challenge, so the browser prompts for a login.

## Logging in

Open `/horizon`. Enter the service account's client ID as the username and its client secret as the password. The browser resends them on the dashboard's own `/horizon/api/...` calls, so nothing is stored in a session and nothing appears in a URL, request logs or the `request_audits` table.

Only the account named `horizon` is accepted. Other service accounts, such as the scheduler's or a collection host's, are rejected even though their credentials are valid elsewhere. `CLIENT_BASIC_AUTH_ENABLED=false` does not open Horizon.

## Creating the account

The secret is stored hashed and is shown once, so it cannot be recovered later.

- New environments: `php artisan db:seed` creates the account through `HorizonServiceAccountSeeder`.
- Existing environments: run `php artisan db:seed --class=HorizonServiceAccountSeeder` or `php artisan service-account:create horizon` once as a deployment step. The container start script only runs `php artisan migrate`, so nothing creates the account automatically.
- Store the printed `client_id:client_secret` value in Secret Manager.
- Rotate the secret with `php artisan service-account:create horizon --regenerate`. The previous secret stops working immediately.

The seeder does nothing if the account already exists, so running it again never changes the credentials.

## Turning protection off

The global-only feature flag `horizon-protection` defaults to `true`. Switching it off lets anyone open Horizon, so do it deliberately and briefly. It needs no seeding: with no stored value it resolves to its default of `true`. Outside this flag there is no per-environment exception. Every environment except `local` is protected, and `local` is always open (Horizon's own behaviour).

## Constraints

- Nothing throttles wrong guesses, and the secret is a random 64-character string, so keep the generated value.
- There is no logout. The browser keeps the credentials until it is closed, and rotating the secret locks everyone out on their next request.
- Horizon runs under the `web` middleware group, so its POST and DELETE routes keep CSRF protection.
- Access is not tied to a person or the `admin` role, so there is no record of who used the credentials.
