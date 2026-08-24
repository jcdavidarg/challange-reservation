#!/bin/sh
set -e

cd /var/www/html

# .env de partida (la imagen ya trae uno copiado del example, pero por si se monta un volumen)
[ -f .env ] || cp .env.example .env

# Forzar la config de base de datos del contenedor dentro del .env.
#
# IMPORTANTE: `php artisan serve` NO transmite el entorno real al worker;
# solo pasa una whitelist (ServeCommand::$passthroughVariables) y espera que
# el worker configure todo leyendo este archivo. Sin esto, el worker ignoraria
# las variables de docker-compose y caeria a DB_CONNECTION=sqlite del .env.example.
set_env_key() {
    key=$1
    value=$2
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        echo "${key}=${value}" >> .env
    fi
}

set_env_key DB_CONNECTION "${DB_CONNECTION:-mysql}"
set_env_key DB_HOST "${DB_HOST:-db}"
set_env_key DB_PORT "${DB_PORT:-3306}"
set_env_key DB_DATABASE "${DB_DATABASE:-reservas}"
set_env_key DB_USERNAME "${DB_USERNAME:-reservas}"
set_env_key DB_PASSWORD "${DB_PASSWORD:-secret}"

# Clave de la app solo si falta
if ! grep -q '^APP_KEY=base64' .env; then
    php artisan key:generate --force
fi

# Por si alguien corre el contenedor montando el codigo sin vendor
if [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist
fi

# Idempotente: los seeders usan firstOrCreate, reiniciar no duplica datos
php artisan migrate --force --seed

exec php artisan serve --host=0.0.0.0 --port="${APP_PORT:-8000}"
