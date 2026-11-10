# Déploiement de l'API Recipe2shop

Serveur : `pierre@192.168.1.81`, dossier `~/recipe2shop-api/`, API en HTTP sur le port hôte **8020**.
Le HTTPS est assuré par le reverse proxy de Pierre : `https://recipe2shop-api.voglimacci.fr` → `http://192.168.1.81:8020`.

Le projet Docker s'appelle `recipe2shop-api`. Il comprend 3 conteneurs (`php`, `worker`, `mysql`) et un volume `recipe2shop-api_mysql_data`. Rien d'autre n'est installé ni modifié sur le serveur.

## Contenu du dossier sur le serveur

| Élément | Origine | Rôle |
|---------|---------|------|
| `api/`, `docker/prod/`, `Dockerfile.prod`, `compose.prod.yaml`, `*.sh`, `VERSION` | archive (remplacés à chaque mise à jour) | code et scripts |
| `.env.local` (chmod 600) | créé par `install.sh` | secrets : APP_SECRET, mots de passe MySQL, JWT_PASSPHRASE, clé Gemini, réglages Gemini |
| `jwt/` | créé par `install.sh` | paire de clés JWT (privée chiffrée, chmod 600) |
| `backups/` | `backup.sh` | sauvegardes de la base |

**Ne jamais supprimer `.env.local` ni `jwt/`.** Sans eux, la base devient inaccessible et tous les utilisateurs sont déconnectés.

## Première installation

Toutes les commandes se lancent depuis la machine qui a l'accès SSH. L'archive est `dist/recipe2shop-api.tar.gz` (construite dans le repo WSL par `deploy/make-dist.sh`).

```bash
# 1. Copier et extraire l'archive (elle contient un dossier recipe2shop-api/)
scp dist/recipe2shop-api.tar.gz pierre@192.168.1.81:/tmp/
ssh pierre@192.168.1.81 'tar -xzf /tmp/recipe2shop-api.tar.gz -C ~ && rm /tmp/recipe2shop-api.tar.gz'

# 2. Déposer la clé Gemini sans l'afficher (install.sh la range dans .env.local puis supprime le fichier)
grep '^GEMINI_API_KEY=' api/.env.local | cut -d= -f2- \
  | ssh pierre@192.168.1.81 'umask 077 && cat > ~/recipe2shop-api/.gemini_api_key'

# 3. Installer : secrets, clés JWT, image, base, migrations, référentiel d'ingrédients, démarrage
ssh pierre@192.168.1.81 'cd ~/recipe2shop-api && ./install.sh'

# 4. Vérifier (1 appel Gemini, compte de test supprimé à la fin)
ssh pierre@192.168.1.81 'cd ~/recipe2shop-api && ./smoke-test.sh'
```

Équivalent PowerShell de l'étape 2 (le repo WSL est lu par `\\wsl.localhost\Ubuntu\home\pierre\perso\recipe2shop`) :
```powershell
(Select-String -Path '\\wsl.localhost\Ubuntu\home\pierre\perso\recipe2shop\api\.env.local' -Pattern '^GEMINI_API_KEY=(.+)$').Matches[0].Groups[1].Value |
  ssh pierre@192.168.1.81 'umask 077 && cat > ~/recipe2shop-api/.gemini_api_key'
```

`install.sh` est idempotent :
- les secrets déjà présents ne sont jamais régénérés ;
- les clés JWT ne sont créées qu'une fois ;
- s'il manque la clé Gemini lors de la première installation, il s'arrête avec un message clair (code ≠ 0).

## Mise à jour

```bash
# Dans le repo WSL : construire l'archive
./deploy/make-dist.sh

scp dist/recipe2shop-api.tar.gz pierre@192.168.1.81:/tmp/
ssh pierre@192.168.1.81 'cd ~/recipe2shop-api && rm -rf api docker && tar -xzf /tmp/recipe2shop-api.tar.gz -C ~ && rm /tmp/recipe2shop-api.tar.gz && ./deploy.sh && ./smoke-test.sh'
```

`deploy.sh` enchaîne :
1. build de l'image ;
2. MySQL ;
3. migrations ;
4. synchronisation du référentiel d'ingrédients ;
5. redémarrage de `php` et du `worker` (le worker garde l'ancien code en mémoire) ;
6. attente de `/health`.

Il sort avec un code ≠ 0 en cas d'échec. `rm -rf api docker` retire les fichiers supprimés entre deux versions ; `.env.local`, `jwt/` et `backups/` ne sont pas touchés.

## Exploitation

```bash
cd ~/recipe2shop-api
C="docker compose --env-file .env.local -f compose.prod.yaml -p recipe2shop-api"

$C ps                                   # état des conteneurs
$C logs -f --tail=100 php worker        # logs JSON (rotation Docker : 5 × 10 Mo par conteneur)
$C logs --no-log-prefix php | grep '"channel":"recipe2shop"'   # événements métier uniquement
$C restart php worker
$C exec php php bin/console app:ai:costs --days=7              # coût Gemini par type d'appel et par session
$C exec php php bin/console messenger:failed:show              # relectures mémoire en échec définitif
$C exec php php bin/console messenger:failed:retry             # … les relancer
$C exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" recipe2shop -e "SELECT name, category FROM ingredient WHERE to_review = 1"'
cat VERSION                             # version déployée
```

Événements journalisés (canal `recipe2shop`, JSON) :
- `auth.registered`, `auth.login_success`, `auth.login_failure`, `auth.account_deleted` ;
- `http.rate_limited` ;
- `gemini.call` (type, modèle, durée, tokens, coût) et `gemini.error` ;
- `gemini.daily_budget_exceeded` ;
- `conversation.turn_failed`, `conversation.greeting_fallback` ;
- `memory_review.done`, `memory_review.failed` ;
- `session.deleted_rule_of_3`.

Les autres canaux (Symfony, Doctrine, Messenger) ne sont journalisés qu'à partir de `warning`.

Ce qui n'est **jamais** journalisé : mots de passe, tokens, textes des messages, valeurs de la mémoire (allergies…), prompts et réponses Gemini. Les emails sont masqués (`a***@example.com`).

## Plafond de dépense Gemini

`GEMINI_DAILY_BUDGET_USD` (2 $ par défaut, dans `.env.local`) plafonne la dépense de tous les utilisateurs par jour UTC, calculée depuis `generation_log`. Au-delà, plus aucun appel n'est fait jusqu'au lendemain :
- les tours renvoient `503 ai_unavailable` ;
- la création de session se rabat sur l'accueil statique.

Pour le modifier : éditer `.env.local`, puis `$C up -d php worker`.

## Sauvegardes

```bash
./backup.sh                 # → backups/recipe2shop-AAAAMMJJ-HHMMSS.sql.gz (garde les 14 dernières)
```
Non planifié. Pour l'automatiser (décision de Pierre) : `crontab -e`, puis
`0 3 * * * cd ~/recipe2shop-api && ./backup.sh >> backups/backup.log 2>&1`.

Restauration (écrase la base) :
```bash
gunzip -c backups/recipe2shop-AAAAMMJJ-HHMMSS.sql.gz | $C exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
```

## Reverse proxy (à configurer par Pierre)

- **Cible** : `http://192.168.1.81:8020`, en HTTP/1.1.
- **En-têtes à transmettre** :
  - `X-Forwarded-For` (en ajoutant l'IP du client) ;
  - `X-Forwarded-Proto: https` ;
  - `X-Forwarded-Host` ;
  - `Host`.
  - L'API ne les accepte que depuis des IP privées (`TRUSTED_PROXIES`). C'est indispensable pour les limites de débit (inscription, connexion), qui doivent voir la vraie IP du client.
- **Timeouts** de lecture et d'envoi **≥ 90 s**. Un tour d'IA avec recette prend environ 15 s, le maximum côté API est d'environ 50 s, et le timeout de l'app est de 60 s.
- **Taille du corps** : 1 Mo suffit (l'API refuse au-delà).
- **HTTPS** : HSTS est envoyé par l'API quand `X-Forwarded-Proto: https` est présent.

Exemple nginx :
```nginx
location / {
    proxy_pass http://192.168.1.81:8020;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    proxy_read_timeout 90s;
    proxy_send_timeout 90s;
    client_max_body_size 1m;
}
```

## Sécurité, en bref

- MySQL n'est pas publié. Seul le port 8020 de l'API l'est.
- Les conteneurs PHP tournent en utilisateur non-root.
- Les secrets ne sont que dans `.env.local` (chmod 600), jamais dans l'image ni dans le repo.
- Les erreurs inattendues renvoient `{"code":500,"error":"server_error"}`, sans trace.
- `/dev/chat-fixture` n'existe pas en production (404).
- CORS est fermé : l'app native n'envoie pas d'en-tête `Origin`.
- En-têtes de sécurité : `nosniff`, `DENY`, CSP `default-src 'none'`, `no-referrer`, `no-store`, et HSTS derrière HTTPS. Les en-têtes `Server` et `X-Powered-By` sont retirés.
- Limites de débit :
  - inscription : 10 par heure et par IP ;
  - connexion : 5 échecs par minute ;
  - tours d'IA : 20 par minute et 300 par jour par utilisateur ;
  - plus le plafond global de dépense Gemini par jour.
