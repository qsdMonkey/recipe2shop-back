#!/bin/sh
set -e

# Cache Symfony construit au démarrage (il dépend des variables d'environnement du conteneur).
php bin/console cache:warmup --no-interaction >/dev/null

exec "$@"
