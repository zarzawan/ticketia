# Imagen PHP-FPM de TicketIA. Se usa junto a nginx y MariaDB via
# docker-compose.yml (la raiz publica es public/, servida por nginx).

# --- Etapa 1: dependencias de Composer (sin dev) ---
FROM composer:2 AS dependencias
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --no-scripts

# --- Etapa 2: runtime ---
FROM php:8.2-fpm-alpine

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/ticketia

COPY public/ public/
COPY src/ src/
COPY bin/ bin/
COPY db/ db/
COPY phinx.php .env.example ./
COPY --from=dependencias /app/vendor/ vendor/

# Almacen de adjuntos fuera del docroot (montar volumen en produccion)
RUN mkdir -p almacen/adjuntos && chown -R www-data:www-data almacen

USER www-data
