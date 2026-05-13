FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git curl unzip libzip-dev libpq-dev \
    libssl-dev libbrotli-dev \
    && docker-php-ext-install pdo pdo_mysql zip pcntl \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

CMD ["php", "-a"]