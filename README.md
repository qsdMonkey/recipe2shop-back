# Recipe2shop — API (back)

API Symfony 7.4 LTS · PHP 8.4 · MySQL 8.4 · Docker (WSL).
Specs et statut (la doc fait foi côté Windows) : `/mnt/c/Users/pvmac/Documents/Dev/recipe2shop/docs/`,
dont `BACK-STATUS.md` (contrat API à jour).

```
.
├── docker-compose.yml       php (FrankenPHP) · worker (Messenger) · mysql · adminer
├── docker/
│   ├── php/                 Dockerfile + entrypoint
│   └── mysql/init/          SQL joué à la création du volume (base de test)
└── api/                     projet Symfony
    └── prompts/             prompts Gemini versionnés
```

## Production
API déployée sur le serveur de Pierre (`192.168.1.81:8020`, derrière `https://recipe2shop-api.voglimacci.fr`).
Fichiers : `Dockerfile.prod`, `compose.prod.yaml`, `docker/prod/`, scripts dans `deploy/`.
Mode d'emploi complet : [deploy/README-DEPLOY.md](deploy/README-DEPLOY.md). Archive : `./deploy/make-dist.sh` → `dist/`.

## Prérequis
- Docker démarré dans WSL (`sudo service docker start`, ou Docker Desktop avec l'intégration WSL).
- Ports libres : 8000 (API), 3307 (MySQL), 8081 (Adminer).

## Démarrer / arrêter
```bash
docker compose up -d --build     # 1er démarrage : build + composer install + migrations (1-2 min)
docker compose ps                # php et mysql doivent être "healthy"
curl http://localhost:8000/health
# → {"status":"ok","db":"ok"}

docker compose logs -f php worker
docker compose down              # arrêter (les données MySQL sont gardées)
docker compose down -v           # arrêter ET effacer la base
```

| Service | URL / accès |
|---------|-------------|
| API | http://localhost:8000 |
| Adminer | http://localhost:8081 (serveur `mysql`, user `app` / `app`, base `recipe2shop`) |
| MySQL depuis WSL | `127.0.0.1:3307` |

## Configuration
- `api/.env` : valeurs d'exemple, commité. **Aucun secret dedans.**
- `api/.env.local` : vraies valeurs, **non commité**. Y mettre `GEMINI_API_KEY=...`.
- Dans Docker, `DATABASE_URL` est fournie par `docker-compose.yml`.

## Authentification (JWT)
- Clés de signature : `api/config/jwt/*.pem`, générées au démarrage du conteneur si absentes, **non commitées**.
- Access token 1 h, refresh token 30 jours à usage unique.
- Régénérer les clés (invalide tous les tokens) : `docker compose exec php bin/console lexik:jwt:generate-keypair --overwrite`.
- Purger les refresh tokens expirés : `docker compose exec php bin/console gesdinet:jwt:clear`.

## IA (Gemini)
- Variables dans `api/.env` (exemples) et `api/.env.local` (vraies valeurs) :
  - `GEMINI_API_KEY` : clé, uniquement dans `.env.local` ;
  - `GEMINI_MODEL` : modèle ;
  - `GEMINI_THINKING_LEVEL_CHAT` / `_PROPOSE_RECIPE` / `_MEMORY_REVIEW` : niveau de réflexion par type d'appel (`low` pour le chat, pour la latence) ;
  - `GEMINI_PRICE_*` : prix par million de tokens, pour calculer le coût.
- Prompts versionnés : `api/prompts/` (voir son README). La version utilisée est configurée dans `config/services.yaml`.
- Chaque appel est tracé dans la table `generation_log` (tokens, coût, durée, modèle, version du prompt), y compris les échecs.
- En test, Gemini est simulé (`tests/Support/FakeGemini.php`) : aucun appel réel, aucun coût.
- Coût et latence récents :
  ```bash
  docker compose exec mysql mysql -uapp -papp recipe2shop -e \
    "SELECT purpose, model, input_tokens, output_tokens, cost_usd, duration_ms, success FROM generation_log ORDER BY id DESC LIMIT 20"
  ```
- Si l'inscription répond 429 en dev après beaucoup de tests (limite de 10 par heure et par IP) : `docker compose exec php bin/console cache:pool:clear cache.rate_limiter`.
- Fixture du chat (dev uniquement) : `GET http://localhost:8000/dev/chat-fixture` (`api/fixtures/chat-fixture.json`).

## Scénarios de test IA et coûts
```bash
docker compose exec php bin/console app:ai:scenarios        # rejoue api/scenarios/*.yaml contre le VRAI Gemini (quelques centimes)
docker compose exec php bin/console app:ai:costs --days=7   # coût et latence Gemini par type d'appel et par session
```
À relancer à chaque changement de prompt ou de modèle. Rapports dans `api/var/scenarios/`. Format des scénarios : `api/scenarios/README.md`.

## MySQL en dev
`docker-compose.yml` lance MySQL avec `--skip-log-bin --innodb-flush-log-at-trx-commit=2` : sous Docker/WSL2, chaque
synchronisation disque coûte 100 à 200 ms, ce qui rendait chaque écriture lente (cocher un article prenait jusqu'à 2,5 s).
Contrepartie : jusqu'à ~1 s d'écritures perdues si la machine plante. **En production, garder les réglages par défaut.**

## Migrations
Lancées automatiquement au démarrage du conteneur `php`. À la main :
```bash
docker compose exec php bin/console doctrine:migrations:migrate
docker compose exec php bin/console make:migration           # après modification d'une entité
```

## Tests
```bash
docker compose exec php bin/phpunit
```
Chaque test tourne dans une transaction annulée (dama/doctrine-test-bundle), et la base de test est migrée
automatiquement au lancement. Les tests utilisent la base `recipe2shop_test` (créée par `docker/mysql/init/01-databases.sql`).
Si le volume MySQL existait avant ce script : `docker compose down -v` puis `up -d`.

## Commandes utiles
```bash
docker compose exec php bin/console debug:router
docker compose exec php composer require <paquet>
docker compose restart worker    # après un changement de code des handlers Messenger
docker compose exec php bin/console messenger:failed:show    # relectures mémoire en échec définitif
docker compose exec php bin/console messenger:failed:retry   # les relancer
docker compose exec php bin/console app:ingredients:sync      # référentiel d'ingrédients (api/data/ingredients.csv), aussi lancé au démarrage
docker compose exec mysql mysql -uapp -papp recipe2shop -e "SELECT name, category FROM ingredient WHERE to_review = 1"   # noms inconnus à ajouter au CSV
```
