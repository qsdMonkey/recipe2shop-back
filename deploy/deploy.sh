#!/usr/bin/env bash
# Mise à jour de l'API Recipe2shop après extraction d'une nouvelle archive. Idempotent, non interactif.
# Usage : cd ~/recipe2shop-api && ./deploy.sh
set -euo pipefail
cd "$(dirname "$0")"

log() { printf '\033[1m[deploy]\033[0m %s\n' "$*"; }
die() { printf '\033[31m[deploy] ERREUR : %s\033[0m\n' "$*" >&2; exit 1; }

[ -f .env.local ] || die ".env.local absent : lancer d'abord ./install.sh"
[ -f jwt/private.pem ] || die "clés JWT absentes : lancer d'abord ./install.sh"
COMPOSE=(docker compose --env-file .env.local -f compose.prod.yaml -p recipe2shop-api)

if [ "${1:-}" != "--skip-build" ]; then
  log "Construction de l'image…"
  "${COMPOSE[@]}" build --pull php
fi

log "Démarrage de MySQL…"
"${COMPOSE[@]}" up -d --wait mysql

log "Migrations…"
"${COMPOSE[@]}" run --rm --no-deps php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
log "Référentiel d'ingrédients…"
"${COMPOSE[@]}" run --rm --no-deps php php bin/console app:ingredients:sync --no-interaction

log "Démarrage de l'API et du worker…"
"${COMPOSE[@]}" up -d --remove-orphans php worker
# Le worker garde l'ancien code en mémoire : on le redémarre à chaque déploiement.
"${COMPOSE[@]}" restart worker

log "Vérification de /health…"
# curl de l'hôte s'il existe (vérifie aussi la publication du port 8020), sinon celui du conteneur.
health() {
  if command -v curl >/dev/null; then curl -fsS http://127.0.0.1:8020/health; else "${COMPOSE[@]}" exec -T php curl -fsS http://localhost:8080/health; fi
}
for _ in $(seq 1 30); do
  if health >/dev/null 2>&1; then
    health; echo
    "${COMPOSE[@]}" ps
    log "Déploiement OK."
    exit 0
  fi
  sleep 3
done
"${COMPOSE[@]}" logs --tail=50 php
die "/health ne répond pas après 90 s"
