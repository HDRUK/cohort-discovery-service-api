# Local Setup

The following guide sets up two services:

1. API, running on port 8100
2. Frontend, running on port 3000

## Database Setup

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=....
DB_USERNAME=...
DB_PASSWORD=....
```

## Misc Setup

### REDIS

```
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=
REDIS_PORT=6379
```

### Open-source mode

While there are two running modes to choose from, it's important to note that "integrated" is used explicitly by HDRUK's Health Data Gateway. When 
forking/cloning and deploying yourself, you should make sure to use "standalone" and configure an OIDC provider or fall-back to username/password
credentials:

```
APP_OPERATION_MODE="standalone"
```

This is documented further down

### Other

```
API_RATE_LIMIT=1000
OCTANE_SERVER=frankenphp # if you use this
```

### Local Development

```
php artisan migrate:fresh --seed
```

### For Deployment

```
php artisan migrate:fresh --seed --seeder=DevDatabaseSeeder
```

## OMOP configuration

Proceed as follows:

-   Option A - if you already have an OMOP Vocab exported from Athena and running in an SQL database already
-   Option B - you just need a minimal OMOP `concept` and `concept_ancestor` table to play with

### [Option A] You have a full OMOP Vocab

Point the following env vars to this DB

```
DB_OMOP_CONNECTION=
DB_OMOP_HOST=
DB_OMOP_PORT=
DB_OMOP_DATABASE=
DB_OMOP_USERNAME=
DB_OMOP_PASSWORD=

```

If you want to get a full OMOP Vocab, or you want to update yours, you must:

1. Got to [https://athena.ohdsi.org/search-terms/start](https://athena.ohdsi.org/search-terms/start)
2. Create an account, if you don't already have one
3. Navigate to 'Download'
   <img width="447" height="100" alt="image" src="https://github.com/user-attachments/assets/ed3ef247-2baa-4828-a401-92776fe7be3d" />

4. Select the VOCABS you need
   <img width="868" height="566" alt="image" src="https://github.com/user-attachments/assets/ca7452fc-d170-4e75-88ab-38039baa8969" />

-   You definitely need 'SNOMED', 'OMOP Gender' and the 'CO-CONNECT' ones
-   You should likely include 'ICD10' and 'ICD9' (multiple vocabs)
-   'Read' codes are common too in UK health data

5. Download these

-   it may take a few minutes - you'll receive an email when your download is ready

6. Extract the data from the .zip file

-   The data will come in csv files (though they're also really tab separated)

7. Load the data into your favourite database (`DB_OMOP_CONNECTION`)

-   We recommend you use [OMOP-lite](https://github.com/health-Informatics-UoN/omop-lite) created by UoN
-   Otherwise you'll have to do this manually and optimise/set indices etc.

### [Option B] You need a minimal OMOP Vocab

You can set `DB_OMOP_CONNECTION` otherwise it will fall back to use `DB_CONNECTION`

Create the tables needed:

```
php artisan migrate
    --database omop
    --path database/migrations_omop
```

Fill minimal OMOP data:

```
php artisan db:seed
    --class MinimalOmopSeeder
    --database=omop
```

## Without BUNNY

If you don't want to use BUNNY in development, you can seed some distribution results for the synthetic datasets by running the following:

```
php artisan db:seed  --class MinimalDistributionsSeeder
```

### (Optional) [Option A]

If you have used Option A you can also run:

```
php artisan concepts:populate-ancestors
```

The purpose of this is that the OMOP model might be in an external location or you have not setup the indexes, so this greatly speeds up concept ancestor/descendant looking up if we copy over the concepts that we actually need - based on what we have locally

## Setting up BUNNY

### Generating synthetic datasets

If you have no synthetic datasets you can generate some with our Synthetic-OMOP [(SOMOP) package](https://github.com/HDRUK/somop)

There are multiple .yaml files for example synthetic datasets of different varieties and sizes. It's best to run them all to get a feel for the different types of datasets that Cohort Discovery could be used for.

Usage:

```
somop --config ../configs/<config>.yaml --out-dir <output directory>
```

You need to also retrieve or create a `CONCEPT.csv` file for each synthetic dataset that you create. You can use the one you have downloaded from athena which is the full OMOP vocab. Otherwise create a table for the Concept and fill it with all the concepts that have been used in the dataset creation (this is quite manual - so it is preferable to use a full OMOP vocab).

E.g. when I have my `CONCEPT.csv` in a `./data` folder, I create four synthetic datasets in the folder `./out`:

```
#!/bin/bash
rm -rf out
for cfg in ckd_antibodies conditions symptoms more_symptoms; do
  somop --config ../configs/$cfg.yaml --out-dir out/$cfg
  cp data/CONCEPT.csv out/$cfg/
done
```

### Loading synthetic datasets

You can use OMOP-lite with a postgres database in docker to load data. [In this example](https://github.com/HDRUK/somop/blob/main/docker/synthetic-omop.yaml), the four synthetic datasets generated are loaded into postgres on port `5435`.

### Running BUNNY

For setting up 8 BUNNYs (2 per dataset, 1 for availability, one for distribution), you can use [this yaml file](https://github.com/HDRUK/somop/blob/main/docker/run-bunny.yaml)

The general structure of setting up a BUNNY should be:

```
name: bunny-daphne

services:
  # --- Dataset 1: COVID-19 Antibody CKD ---
  covid-ckd-a:
    image: ghcr.io/health-informatics-uon/hutch/bunny:edge
    environment:
      DATASOURCE_DB_USERNAME: postgres
      DATASOURCE_DB_PASSWORD: postgres
      DATASOURCE_DB_DATABASE: omop1
      DATASOURCE_DB_DRIVERNAME: postgresql
      DATASOURCE_DB_SCHEMA: public
      DATASOURCE_DB_HOST: host.docker.internal
      DATASOURCE_DB_PORT: 5435
      BUNNY_LOGGER_LEVEL: DEBUG
```

You then need to configure:

| Env variable      | Description co                                                                                 | Example                                   |
| ----------------- | ---------------------------------------------------------------------------------------------- | ----------------------------------------- |
| TASK_API_BASE_URL | URL of the daphne API                                                                          | `http://host.docker.internal:8100/api/v1` |
| TASK_API_USERNAME | Client ID of Collection Host                                                                   | <user name string >                       |
| TASK_API_PASSWORD | Client secret of Collection Host                                                               | <password string >                        |
| TASK_API_TYPE     | Availability of Distribution task. You need to setup two BUNNYs per dataset for each task type | `a` or `b`                                |
| COLLECTION_ID     | The collection ID from the daphne-api for this dataset                                         | A `pid` that is a uuid()                  |

For more information [follow the instructions here](https://hutch.health/bunny/config)

# Standalone Integration

In standalone mode the Cohort-Discovery-Service API is its own identity authority. Users live in the
local `users` table, log in with email + password via `POST /api/auth/login`, and receive an RS256 JWT
signed with the Passport keys.

```
APP_OPERATION_MODE="standalone"
STANDALONE_JWT_TTL_MINUTES=120 # how long a login lives before the user has to go around again
```

You'll need Passport keys in place (`php artisan passport:keys` if you don't have them already) and the
`CohortDiscoveryService` personal access client, which the seeders (`StandaloneDemoSeeder` / `TestingSeeder`) take
care of for you. No keys, no tokens - it's that kind of relationship.

## OIDC Single Sign-On (optional)

Once deployed you can also hand the front door over to an external identity provider - anything that
speaks OIDC will do (Keycloak, Entra ID, Google, your institution's finest). Password login carries on
working alongside it; SSO is an additional way in, not a replacement.

A word of warning before you start: SSO is *standalone-only*. In integrated mode the Health Data Gateway *is* the
identity provider, so these endpoints politely 404 and pretend they don't exist.

### Configuration

Add the following to your `.env`:

```
SSO_ENABLED=true
SSO_FRONTEND_CALLBACK_URL="http://localhost:3000/auth/sso/callback"                   # or wherever your client deployment lives
SSO_FRONTEND_ERROR_URL="http://localhost:3000/auth/sso/error"                         # where failed logins get sent

SSO_DEFAULT_ENABLED=true
SSO_DEFAULT_LABEL="Single Sign-On"                                                    # what the FE shows on the button
SSO_DEFAULT_ISSUER="http://localhost:8080/realms/cohort-discovery-service"            # your IdP's issuer, exactly as it appears in its tokens
SSO_DEFAULT_CLIENT_ID=<client id you registered with the IdP>
SSO_DEFAULT_CLIENT_SECRET=<client secret to go with it>
SSO_DEFAULT_SCOPES="openid profile email"
```

The `DEFAULT` in the variable names refers to the provider slug (`default`), which appears in the URLs
below. Additional providers can be added under `config/sso.php` per deployment - each gets its own slug.

There are also some optional dials, all with sensible defaults, if you feel the need to fine tune:

```
SSO_DEFAULT_DISCOVERY_URL=      # defaults to {issuer}/.well-known/openid-configuration
SSO_DEFAULT_REDIRECT_URI=       # defaults to {app url}/api/auth/sso/default/callback
SSO_TXN_TTL_SECONDS=600         # how long a login attempt can idle between redirect and callback
SSO_HANDOFF_CODE_TTL_SECONDS=60 # how long the FE has to exchange its one-time code
SSO_ID_TOKEN_LEEWAY_SECONDS=30  # clock-skew forgiveness when validating id_tokens
```

### Register the callback with your IdP

Your IdP needs to know where to send people back to. Register:

```
http://localhost:8100/api/auth/sso/default/callback # or wherever you are running your instance of the cohort-discovery-service-api
```

Make sure the IdP actually releases the `email` claim (it's in the default scopes, but some enterprise
setups withhold it) - without an email we can neither link nor create an account, and the login will fail.

### OIDC Flow

1. FE asks `GET /api/auth/sso/providers` for what's on offer and renders a button per provider
2. The browser navigates to `GET /api/auth/sso/default/redirect` and is bounced to the IdP
3. The IdP bounces back to our callback; we validate the id_token (signature, issuer, audience,
   nonce and expiry), find or create the user, mint the same
   RS256 JWT a password login would get, and bounce the browser to `SSO_FRONTEND_CALLBACK_URL`
   with a single-use `?code=`
4. The FE swaps that code at `POST /api/auth/sso/exchange` (`{"code": "..."}`) for the actual
   `access_token` - so the token itself never rides in a URL

First-time visitors are created on the spot with the `DEFAULT` workgroup and the `user` role
(controllable via the `sso-ensure-defaults-on-jit` feature flag). Returning visitors are matched by
their provider identity. If someone arrives whose email matches an existing account, we only link the
two when the IdP swears the email is verified - anything less smells like an account takeover, and we
send it to the error URL instead.

### Trying it locally

A disposable Keycloak is the quickest way to confirm the flow:

```
docker run -p 8080:8080 -e KC_BOOTSTRAP_ADMIN_USERNAME=admin -e KC_BOOTSTRAP_ADMIN_PASSWORD=admin quay.io/keycloak/keycloak start-dev
```

Create a realm, a confidential client with the callback URL above, a test user with a verified email,
point the `SSO_DEFAULT_*` vars at it, and visit `http://localhost:8100/api/auth/sso/default/redirect`
in a browser.

# Gateway Integration

To use Cohort Discovery Service with the HDR UK Gateway, follow the next steps to setup this as an integration

Add the following to your .env for the gateway-api:

```
COHORT_DISCOVERY_URL="http://localhost:3000" # or wherever you intend on running the DAPHNE FE
COHORT_DISCOVERY_AUTH_URL="http://localhost:8100/auth/callback" # or whereever you intend on running the DAPHNE BE API
COHORT_DISCOVERY_SERVICE_ACCOUNT=cohort-service@hdruk.ac.uk
COHORT_DISCOVERY_USE_OAUTH2=true
COHORT_DISCOVERY_ADD_TEAMS_TO_JWT=true
```

Call this special seeder:

```
php artisan db:seed --class=CohortServiceUserSeeder
```

This will create a service account for Cohort Discovery as well as a new entry in the `oauth_clients` table.

Copy the value for `id` and `secret` that has been created by this seeding, it should named "cohort-discovery-oauth-client" by default.

Now you'll need to setup the following in your daphne API:

```
INTEGRATED_CLIENT_ID=<oauth_clients id you just generated>
INTEGRATED_CLIENT_SECRET=<oauth_clients secret you just generated>
OAUTH_PLACEHOLDER_PASSWORD="oauth_user"
OAUTH_INTERNAL_REDIRECT="http://localhost:8100/auth/callback" # or wherever you are running your instance of the daphne-api
CLIENT_BASIC_AUTH_ENABLED=true # note: this might be redundant
```

You also need to setup the following:

```
APP_OPERATION_MODE="integrated"
INTEGRATED_JWT_SECRET=<this needs to be the same as the JWT_SECRET you set in the gateway-API>
INTEGRATED_API_URI="http://localhost:8000/api/v1/" # or wherever you are running your instance of the gateway-api
INTEGRATED_AUTHORISATION_URI="http://localhost:8000/oauth2/token" # or wherever you are running your instance of the gateway-api
```

# License

[MIT license](https://opensource.org/licenses/MIT).
