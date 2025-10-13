# Local Setup

## Database Setup

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=....
DB_USERNAME=...
DB_PASSWORD=....
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

## Setting up BUNNY

... to be completed....

# License

[MIT license](https://opensource.org/licenses/MIT).
