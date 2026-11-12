# Prompts versionnés

Les prompts envoyés à Gemini sont des fichiers, jamais du texte en dur dans le PHP.
Pour changer un prompt : créer `<nom>_v2.md`, puis pointer le paramètre correspondant dans `config/services.yaml`.
La version utilisée est enregistrée dans `generation_log.prompt_version` à chaque appel.

| Fichier | Rôle |
|---------|------|
| `system_v7.md` | Prompt système des sessions de courses (v7 : start_cooking « pas à pas », quantités en poids dans add_list_items). v6 : recettes favorites, v5 : nom de l'app « Recett'IA », v4 : listes, v3 : recettes, v2 : mémoire, v1 : étape 3 |
| `recipe_v4.md` | Génération d'une recette (appel dédié, JSON imposé par responseSchema ; v4 : quantités en poids, pas-à-pas détaillé, astuces ; v3 : accents exigés ; v2 : nom « Recett'IA ») |
| `memory_review_v2.md` | Relecture mémoire de fin de session (JSON imposé ; v2 : nom « Recett'IA ») |
| `memory_review_intro.md` | Texte au-dessus des puces de la relecture, au début de la session suivante |
| `onboarding_v3.md` | Prompt système de l'onboarding (v3 : nom « Recett'IA » ; v2 : update_memory / finish_onboarding). v1 : étape 3 |
| `kickoff_v1.md` | Message automatique (invisible) qui déclenche le message d'accueil de l'IA |
| `empty_answer_fallback.md` | Réponse de repli si Gemini ne renvoie aucun texte |
| `greeting_fallback_shopping.md` / `greeting_fallback_onboarding.md` | Accueil statique si Gemini est indisponible à la création d'une session |
