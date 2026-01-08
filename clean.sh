#!/bin/bash

composer dump-autoload
composer dump-autoload -o

php artisan clear-compiled
php artisan optimize

php artisan route:clear
php artisan view:clear

php artisan cache:clear
php artisan config:cache
php artisan config:clear
