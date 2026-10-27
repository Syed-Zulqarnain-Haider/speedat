# PHP 8.5.12 (what the live site runs, from 2026-10-27) with the extensions Hostinger's shared plan has switched on, for
# running the site and bin/test.php on a machine without PHP installed.
FROM php:8.5.12-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libpng-dev libonig-dev nodejs git zip unzip \
 && docker-php-ext-install pdo_mysql mbstring zip gd \
 && rm -rf /var/lib/apt/lists/*

# No opcache in the list: from PHP 8.5 it is compiled in and always present,
# and asking docker-php-ext-install for it fails the build.

WORKDIR /app
