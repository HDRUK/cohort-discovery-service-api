# syntax=docker/dockerfile:1.4
FROM dunglas/frankenphp:php8.4

ENV COMPOSER_PROCESS_TIMEOUT=600
ENV REBUILD_DB=1

WORKDIR /var/www

COPY composer.* /var/www/

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

COPY ./init/php.development.ini /usr/local/etc/php/php.ini

# Install Redis
RUN pecl install redis-6.3.0 \
    && rm -rf /tmp/pear

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin --filename=composer


# Copy the application
COPY . /var/www

# Composer & Laravel
RUN --mount=type=secret,id=github_token \
    mkdir -p /tmp/composer \
    && GITHUB_TOKEN="$(cat /run/secrets/github_token)" \
    && printf '%s' "{\"github-oauth\":{\"github.com\":\"${GITHUB_TOKEN}\"}}" > /tmp/composer/auth.json \
    && export COMPOSER_HOME=/tmp/composer \
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
