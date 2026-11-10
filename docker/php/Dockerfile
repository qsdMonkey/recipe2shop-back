# Image PHP de l'API : FrankenPHP (serveur HTTP Caddy + PHP dans un seul binaire).
FROM dunglas/frankenphp:1-php8.4

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl git unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pdo_mysql intl opcache zip apcu

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

# Utilisateur non-root avec le même UID/GID que l'utilisateur WSL,
# pour que les fichiers créés dans le bind mount (var/, vendor/) restent à lui.
RUN groupadd -g ${GID} app \
    && useradd -u ${UID} -g ${GID} -m app \
    && chown -R app:app /data/caddy /config/caddy

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

ENV COMPOSER_HOME=/tmp/composer \
    # Port HTTP interne (non privilégié, car le conteneur tourne en non-root). Pas de HTTPS en dev.
    SERVER_NAME=":8080"

USER app
WORKDIR /app

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
