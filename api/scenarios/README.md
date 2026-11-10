# Scénarios de test IA

Conversations scriptées rejouées contre le **vrai Gemini** (POC.md, « Critères de réussite »), à relancer à chaque
changement de prompt ou de modèle :

```bash
docker compose exec php bin/console app:ai:scenarios                 # tous
docker compose exec php bin/console app:ai:scenarios courses_au_mois # un seul
```

Rapport Markdown + JSON dans `api/var/scenarios/` (statut, contrôles, déroulé, durée, coût). Les comptes de test sont
supprimés à la fin (`--keep` pour les garder). Coût d'un passage complet : quelques centimes (voir le rapport).

## Format d'un scénario
- `memory` : profil de départ (sinon : compte neuf, à faire passer par l'onboarding).
- `steps` : `start: onboarding|shopping`, `say: "…"`, `accept_until_covered: {max, meals?}`, `reject_pending: "commentaire"`,
  `finish_onboarding: true`, `new_session: true` (clôture → relecture mémoire par le worker).
- `expect` d'une étape : `tools`, `tools_not`, `text_matches`, `text_not_matches` (préfixe `soft_` = avertissement).
- `checks` (bloquants) / `soft_checks` (avertissements) : `memory_contains`, `memory_not_contains`, `onboarding_completed`,
  `blocks_seen`, `tools_seen`, `max_ai_seconds`, `period_days`, `recipe_tool_errors_max`, `recipes_exclude`,
  `meals_covered`, `list_generated`, `inventory_in_list`, `recipes_difficulty`, `recipes_tag_any`.

Le temps mesuré est le temps de réponse de l'IA (sans le temps de lecture / saisie de l'utilisateur).
