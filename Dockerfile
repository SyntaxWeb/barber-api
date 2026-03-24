FROM ghcr.io/syntaxweb/php-base:8.3-alpine AS builder

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts

COPY . .

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --classmap-authoritative --no-scripts

FROM ghcr.io/syntaxweb/php-base:8.3-alpine

WORKDIR /var/www/html

COPY --from=builder --chown=appuser:appuser /var/www/html /var/www/html

EXPOSE 8000
