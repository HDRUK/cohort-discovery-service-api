#!/bin/bash

set -euo pipefail

if [ -f /var/www/.env ]; then
    # shellcheck disable=SC1091
    source /var/www/.env
fi

APP_ENV="${APP_ENV:-production}"
REBUILD_DB="${REBUILD_DB:-0}"

base_command="php artisan octane:frankenphp --max-requests=250 --host=0.0.0.0 --port=8100"

if [ "${DB_CONNECTION:-}" = "sqlite" ]; then
    db_path="${DB_DATABASE:-database/database.sqlite}"
    mkdir -p "$(dirname "$db_path")"
    touch "$db_path"
fi

if [ "$APP_ENV" = "local" ] || [ "$APP_ENV" = "dev" ]; then
    echo 'running in dev mode - with watch'
    # base_command="$base_command --watch"

    if [ "$REBUILD_DB" = "1" ]; then
        php artisan migrate:fresh --seed --seeder=DevDatabaseSeeder
    else
        php artisan migrate
    fi
else
    php artisan migrate
    php artisan validation:generate-logs

    echo "running in prod mode"
fi

if [ -n "${OCTANE_WORKERS:-}" ]; then
    base_command="$base_command --workers=${OCTANE_WORKERS}"
fi

php artisan optimize:clear
php artisan optimize

$base_command &

php artisan horizon

