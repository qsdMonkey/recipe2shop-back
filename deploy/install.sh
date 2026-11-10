#!/usr/bin/env bash
# Première installation (et réparation) de l'API Recipe2shop sur le serveur. Idempotent, non interactif.
# Prérequis : le code extrait dans ce dossier, et la clé Gemini dans ./.gemini_api_key (chmod 600) lors de la
# première installation (ensuite facultatif : s'il est présent, il remplace la clé existante).
# Usage : cd ~/recipe2shop-api && ./install.sh
set -euo pipefail
cd "$(dirname "$0")"
umask 077

log() { printf '\033[1m[install]\033[0m %s\n' "$*"; }
die() { printf '\033[31m[install] ERREUR : %s\033[0m\n' "$*" >&2; exit 1; }
random() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$1" || true; }

command -v docker >/dev/null || die "docker introuvable"
docker compose version >/dev/null || die "docker compose introuvable"

# --- Secrets (.env.local) : générés une seule fois, jamais régénérés (sinon la base et les tokens deviennent inutilisables).
touch .env.local
chmod 600 .env.local
set_if_missing() { grep -q "^$1=" .env.local || echo "$1=$2" >> .env.local; }

set_if_missing APP_SECRET "$(random 48)"
set_if_missing MYSQL_PASSWORD "$(random 40)"
set_if_missing MYSQL_ROOT_PASSWORD "$(random 40)"
set_if_missing JWT_PASSPHRASE "$(random 48)"
set_if_missing GEMINI_MODEL gemini-3.8-flash
set_if_missing GEMINI_PRICE_INPUT_PER_MTOK 0.75
set_if_missing GEMINI_PRICE_OUTPUT_PER_MTOK 3.75
set_if_missing GEMINI_DAILY_BUDGET_USD 2
set_if_missing GEMINI_THINKING_LEVEL_CHAT low
set_if_missing GEMINI_THINKING_LEVEL_PROPOSE_RECIPE low
set_if_missing GEMINI_THINKING_LEVEL_MEMORY_REVIEW medium
set_if_missing TRUSTED_PROXIES "127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16"

# --- Clé Gemini : lue dans .gemini_api_key (jamais affichée), puis le fichier est supprimé.
if [ -f .gemini_api_key ]; then
  key="$(tr -d '[:space:]' < .gemini_api_key)"
  [ -n "$key" ] || die ".gemini_api_key est vide"
  grep -v '^GEMINI_API_KEY=' .env.local > .env.local.tmp || true
  echo "GEMINI_API_KEY=$key" >> .env.local.tmp
  mv .env.local.tmp .env.local
  chmod 600 .env.local
  rm -f .gemini_api_key
  unset key
  log "Clé Gemini enregistrée dans .env.local (fichier .gemini_api_key supprimé)"
fi
grep -q '^GEMINI_API_KEY=.\+' .env.local || die "clé Gemini absente : déposer ./.gemini_api_key (chmod 600) puis relancer ./install.sh"

# --- Image
log "Construction de l'image…"
docker compose --env-file .env.local -f compose.prod.yaml -p recipe2shop-api build --pull php

# --- Clés JWT : générées une seule fois sur le serveur (chiffrées avec JWT_PASSPHRASE).
mkdir -p jwt backups
# Le conteneur tourne en uid 1000, qui n'est pas forcément l'uid de l'hôte : il doit pouvoir TRAVERSER jwt/
# (les fichiers eux-mêmes appartiennent à 1000, private.pem en 600).
chmod 711 jwt
if [ ! -f jwt/private.pem ]; then
  log "Génération des clés JWT…"
  passphrase="$(grep '^JWT_PASSPHRASE=' .env.local | cut -d= -f2-)"
  docker run --rm --user root -e JWT_PASSPHRASE="$passphrase" -v "$PWD/jwt:/jwt" --entrypoint php recipe2shop-api:latest -r '
    $key = openssl_pkey_new(["private_key_bits" => 4096, "private_key_type" => OPENSSL_KEYTYPE_RSA]) or exit(1);
    openssl_pkey_export($key, $private, getenv("JWT_PASSPHRASE")) or exit(1);
    file_put_contents("/jwt/private.pem", $private);
    file_put_contents("/jwt/public.pem", openssl_pkey_get_details($key)["key"]);
    chown("/jwt/private.pem", 1000); chown("/jwt/public.pem", 1000);
    chmod("/jwt/private.pem", 0600); chmod("/jwt/public.pem", 0644);'
  unset passphrase
  [ -s jwt/private.pem ] || die "génération des clés JWT échouée"
fi

# --- Base, migrations, référentiel, démarrage
./deploy.sh --skip-build
log "Installation terminée."
