FROM php:8.4-cli

# Extensiones requeridas por Laravel/Sanctum + zip/unzip para Composer
RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip libzip-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencias primero: capa cacheada mientras no cambien composer.json/lock
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .

# .env de partida sin secretos; el entrypoint genera APP_KEY en el primer boot.
# dump-autoload con .env presente permite que corra package:discover (registra Sanctum).
RUN cp .env.example .env \
    && composer dump-autoload --optimize

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["entrypoint.sh"]
