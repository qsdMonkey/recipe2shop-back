#!/usr/bin/env bash
# Vérifications après déploiement (sur le serveur). Fait 1 appel Gemini (création de session), puis supprime le
# compte de test. Vérifie aussi que les logs sont en JSON et que l'email de test n'y apparaît jamais en clair.
set -euo pipefail
cd "$(dirname "$0")"
COMPOSE=(docker compose --env-file .env.local -f compose.prod.yaml -p recipe2shop-api)

since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
out="$("${COMPOSE[@]}" exec -T php php bin/smoke-test.php)" || { echo "$out"; echo "SMOKE TEST : ÉCHEC" >&2; exit 1; }
echo "$out" | grep -v '^SMOKE_EMAIL='
email="$(echo "$out" | sed -n 's/^SMOKE_EMAIL=//p')"

sleep 2
logs="$("${COMPOSE[@]}" logs --no-color --no-log-prefix --since "$since" php worker 2>&1 || true)"
lines="$(echo "$logs" | grep -c . || true)"
non_json="$(echo "$logs" | grep -v '^{' | grep -c . || true)"
leaks="$(echo "$logs" | grep -c -F "$email" || true)"
masked="$(echo "$logs" | grep -c 's\*\*\*@example.com' || true)"
echo "Logs depuis $since : $lines lignes, $non_json non-JSON, email en clair : $leaks, email masqué : $masked"
[ "$leaks" -eq 0 ] || { echo "ERREUR : l'email de test apparaît en clair dans les logs" >&2; exit 1; }
[ "$non_json" -eq 0 ] || { echo "ATTENTION : lignes de log non-JSON :" >&2; echo "$logs" | grep -v '^{' | head -5 >&2; }
echo "SMOKE TEST : OK"
