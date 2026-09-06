# Imagen PHP-FPM de TicketIA. Se usa junto a nginx y MariaDB via
# docker-compose.yml (la raiz publica es public/, servida por nginx).

# --- Etapa 1: dependencias de Composer (sin dev) ---
FROM composer:2 AS dependencias
WORKDIR /app
COPY composer.json composer.lock ./
# La imagen de composer no trae pdo_mysql; la extension existe en el runtime.
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --no-scripts \
    --ignore-platform-req=ext-pdo_mysql

# --- Etapa 2: runtime ---
FROM php:8.2-fpm-alpine

RUN set -eux; \
    apk add --no-cache libcurl oniguruma; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev oniguruma-dev; \
    docker-php-ext-install -j"$(nproc)" curl mbstring opcache pdo_mysql; \
    apk del .build-deps; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/ticketia.ini"

WORKDIR /var/www/ticketia

COPY --chown=www-data:www-data public/ public/
COPY --chown=www-data:www-data src/ src/
COPY --chown=www-data:www-data bin/ bin/
COPY --chown=www-data:www-data db/ db/
COPY --chown=www-data:www-data phinx.php .env.example ./
COPY --from=dependencias --chown=www-data:www-data /app/vendor/ vendor/

# Almacen de adjuntos fuera del docroot (montar volumen en produccion)
RUN mkdir -p almacen/adjuntos && chown -R www-data:www-data almacen

USER www-data
