# Horizon access

Horizon is restricted to users with the `admin` role. There is no separate Horizon login or shared secret.

## How it works

- The web app stores the user's JWT in an HttpOnly cookie named `token` (`COOKIE_TOKEN_NAME`). The browser sends it to `/horizon` automatically when the API is served on the same hostname as the web app.
- `App\Http\Middleware\AuthenticateHorizonFromCookie` runs first on every Horizon route (`config/horizon.php`). It passes the cookie value to the existing `DecodeJwt` middleware, so signature, expiry and user lookup behave exactly as they do for the API in both standalone and integrated modes.
- The `viewHorizon` gate in `app/Providers/HorizonServiceProvider.php` allows the request only if the authenticated user has the `admin` role. Anyone else gets a 403 from Horizon.
- Admins only need to be logged in to the web app, then visit `/horizon`.

## Environments

- `local` is always open (Horizon's own check).
- Every other environment (dev, preprod, production) is admin-only, with no exceptions and no opt-out.

## Constraints

- The API and the web app must share a hostname. The web cookie is host-only, so it is not sent to a different host or subdomain.
- The token is read from the cookie only. The query string and the `Authorization` header are ignored, so a token never appears in a URL, request logs or the `request_audits` table.
- Horizon runs under the `web` middleware group, so its POST and DELETE routes keep CSRF protection. The cookie is `SameSite=Lax`, which stops cross-site requests carrying it.
- Standalone tokens are not checked for revocation and last until they expire, so removing the `admin` role takes effect on the next request, but revoking a token does not.
