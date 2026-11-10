#!/bin/sh
set -e

# Premier démarrage : installe les dépendances si vendor/ est absent.
if [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --prefer-dist
fi

# Clés de signature JWT (config/jwt/*.pem, non commitées) : générées si absentes.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php bin/console lexik:jwt:generate-keypair --skip-if-exists
fi

# Seul le conteneur "php" lance les migrations (RUN_MIGRATIONS=1), pas le worker.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    php bin/console app:ingredients:sync
fi

exec "$@"
