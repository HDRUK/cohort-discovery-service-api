#!/bin/bash

# set -euo pipefail

# Preserve Cloud Run's PORT (or default to 8080 if running locally)
CLOUD_RUN_PORT="${PORT:-8080}"

APP_ENV="${APP_ENV:-production}"
REBUILD_DB="${REBUILD_DB:-0}"
START_HORIZON="${START_HORIZON:-1}"
PORT="${CLOUD_RUN_PORT}"

base_command="php artisan octane:frankenphp --host=0.0.0.0 --port=${PORT}"

if [ "${DB_CONNECTION:-}" = "sqlite" ]; then
    db_path="${DB_DATABASE:-database/database.sqlite}"
    mkdir -p "$(dirname "$db_path")"
    touch "$db_path"
fi

if [ "$APP_ENV" = "local" ] || [ "$APP_ENV" = "dev" ]; then
    echo 'running in dev mode'
    # base_command="$base_command --watch"

    if [ "$REBUILD_DB" = "1" ]; then
        php artisan migrate:fresh --seed --seeder=DevDatabaseSeeder
    else
        php artisan migrate
    fi
else
    echo "running in prod mode"
    php artisan migrate --force
fi

if [ -n "${OCTANE_WORKERS:-}" ]; then
    base_command="$base_command --workers=${OCTANE_WORKERS}"
fi


composer dump-autoload
composer dump-autoload -o

php artisan clear-compiled
php artisan optimize:clear

php artisan route:clear
php artisan view:clear
php artisan cache:clear
php artisan config:clear

php artisan optimize
php artisan config:cache


if [ "${START_HORIZON:-1}" = "1" ]; then
    echo "Starting Horizon in background..."
    php artisan horizon &
    echo "Checking horizon...."
    php artisan horizon:status
    php artisan horizon:supervisors
    echo "Checked horizon...."
fi

echo "Starting Octane on port ${PORT}..."

exec $base_command
