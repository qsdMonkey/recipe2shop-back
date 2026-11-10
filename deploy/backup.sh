#!/usr/bin/env bash
# Sauvegarde de la base (mysqldump compressé) dans ./backups, en gardant les 14 dernières.
# Non planifiée : à ajouter au cron si Pierre le décide, par exemple :
#   0 3 * * * cd ~/recipe2shop-api && ./backup.sh >> backups/backup.log 2>&1
set -euo pipefail
cd "$(dirname "$0")"
umask 077
mkdir -p backups

file="backups/recipe2shop-$(date +%Y%m%d-%H%M%S).sql.gz"
docker compose --env-file .env.local -f compose.prod.yaml -p recipe2shop-api exec -T mysql \
  sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --no-tablespaces --databases recipe2shop' \
  | gzip > "$file"
[ -s "$file" ] || { echo "Sauvegarde vide : échec" >&2; rm -f "$file"; exit 1; }
echo "Sauvegarde : $file ($(du -h "$file" | cut -f1))"

ls -1t backups/recipe2shop-*.sql.gz | tail -n +15 | xargs -r rm -f
