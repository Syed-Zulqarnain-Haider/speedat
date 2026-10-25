# PHP 8.2 with the extensions Hostinger's shared plan has switched on, for
# running the site and bin/test.php on a machine without PHP installed.
FROM php:8.2-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libpng-dev libonig-dev nodejs git zip unzip \
 && docker-php-ext-install pdo_mysql mbstring zip gd opcache \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
