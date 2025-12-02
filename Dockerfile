# syntax=docker/dockerfile:1.4
FROM dunglas/frankenphp:php8.4

ENV COMPOSER_PROCESS_TIMEOUT=600
ENV REBUILD_DB=1
ENV DOCKER_BUILDKIT="1"

WORKDIR /var/www

RUN ls -ltr /var/www

COPY composer.* /var/www/

RUN --mount=type=secret,id=composer_auth \
    echo "Listing /run/secrets:" && ls -ltr /run/secrets && \
    echo "Showing first 80 chars of composer_auth:" && head -c 80 /run/secrets/composer_auth && echo


RUN apt-get update && apt-get install -y \
    nodejs \
    npm \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libmcrypt-dev \
    libxml2-dev \
    libzip-dev \
    libc-dev \
    wget \
    zlib1g-dev \
    zip \
    default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo pdo_mysql soap zip iconv bcmath \
    && docker-php-ext-configure pdo_mysql --with-pdo-mysql=mysqlnd \
    && docker-php-ext-install sockets \
    && docker-php-ext-install exif \
    && docker-php-ext-configure pcntl --enable-pcntl \
    && docker-php-ext-install pcntl \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /etc/pki/tls/certs && \
    ln -s /etc/ssl/certs/ca-certificates.crt /etc/pki/tls/certs/ca-bundle.crt

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin --filename=composer

# Copy the application
COPY . /var/www


RUN cat /run/secrets/composer_auth

# Composer & laravel
RUN --mount=type=secret,id=composer_auth \
    && export COMPOSER_AUTH="$(cat /run/secrets/composer_auth)" \
    && composer install --no-interaction --prefer-dist --optimize-autoloader \
    && chmod -R 777 storage bootstrap/cache \
    && php artisan octane:install --server=frankenphp --no-interaction \
    && php artisan storage:link \
    # && php artisan optimize:clear \
    # && php artisan optimize \
    && php artisan config:clear \
    && chmod -R 777 storage bootstrap/cache \
    && chown -R www-data:www-data storage \
    && composer dump-autoload

RUN php artisan l5-swagger:generate

# Cleanup unwanted files
RUN rm /var/www/public/.htaccess

# Starts both, laravel server and job queue
CMD ["/var/www/docker/start.sh"]

# Expose port
EXPOSE 8100
