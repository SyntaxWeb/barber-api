FROM php:8.2-fpm

# Instalar extensões do PHP
RUN apt-get update && apt-get install -y \
    curl zip unzip git libonig-dev libxml2-dev libzip-dev libpng-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring xml zip gd \
    && pecl install redis \
    && docker-php-ext-enable redis

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
