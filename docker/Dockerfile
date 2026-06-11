FROM php:8.2-fpm-alpine

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/ticketia

# Composer (para instalar dependencias dentro del contenedor si hace falta)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
