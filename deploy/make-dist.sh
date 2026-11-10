#!/usr/bin/env bash
# Construit l'archive de déploiement dist/recipe2shop-api.tar.gz depuis le repo (côté WSL).
# Contenu : code de l'API (sans vendor, var, tests, secrets ni clés), fichiers Docker de prod, scripts, README-DEPLOY.md.
set -euo pipefail
cd "$(dirname "$0")/.."

out=dist/recipe2shop-api.tar.gz
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p dist "$stage/recipe2shop-api"
dst="$stage/recipe2shop-api"

cp Dockerfile.prod compose.prod.yaml .dockerignore "$dst/"
mkdir -p "$dst/docker" && cp -r docker/prod "$dst/docker/prod"
cp deploy/install.sh deploy/deploy.sh deploy/backup.sh deploy/smoke-test.sh deploy/README-DEPLOY.md "$dst/"
tar -C . -cf - \
  --exclude='api/vendor' --exclude='api/var' --exclude='api/tests' --exclude='api/.phpunit.cache' \
  --exclude='api/.env.local' --exclude='api/.env.*.local' --exclude='api/.env.dev' --exclude='api/.env.test' \
  --exclude='api/config/jwt/*.pem' --exclude='api/scenarios/*.md' --exclude='api/phpunit.dist.xml' \
  api | tar -C "$dst" -xf -

version="$(git rev-parse --short HEAD 2>/dev/null || echo 'non-commité')$(git diff --quiet HEAD -- 2>/dev/null || echo '+modifs')"
echo "$version $(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$dst/VERSION"

# Garde-fou : aucun secret ni clé dans l'archive.
if grep -rIl --exclude='*.md' -e 'BEGIN .*PRIVATE KEY' -e '^GEMINI_API_KEY=AI' "$dst" ; then
  echo "ERREUR : secret détecté dans l'archive" >&2; exit 1
fi
for f in api/.env.local api/config/jwt/private.pem api/vendor .env.local jwt; do
  [ ! -e "$dst/$f" ] || { echo "ERREUR : $f ne doit pas être dans l'archive" >&2; exit 1; }
done

tar -C "$stage" -czf "$out" recipe2shop-api
echo "Archive : $out ($(du -h "$out" | cut -f1)) — version $(cat "$dst/VERSION")"
