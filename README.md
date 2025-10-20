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

### Integration mode

There are two running modes to choose from:

```
APP_OPERATION_MODE="integrated" # or "standalone"
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

### [Option A] You already have a full OMOP Vocab

Point the following env vars to this DB

```
DB_OMOP_CONNECTION=
DB_OMOP_HOST=
DB_OMOP_PORT=
DB_OMOP_DATABASE=
DB_OMOP_USERNAME=
DB_OMOP_PASSWORD=

```

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

... to be completed ...

# Standalone Integration

... to be completed ...

# Gateway Integration

To use DAPHNE with the HDRUK Gateway, follow the next steps to setup this as an integration

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
