FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    libzip-dev libssl-dev \
    && docker-php-ext-install pdo pdo_mysql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini