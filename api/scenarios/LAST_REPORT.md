# Scénarios IA — 2026-11-04 18-01

Modèle `gemini-3.8-flash` · prompts `onboarding_v2`, `system_v4`, `recipe_v1`, `memory_review_v1`

| Scénario | Statut | Tours | Temps IA | Appels Gemini | Coût |
|---|---|---|---|---|---|
| onboarding_famille | ✅ | 3 | 15 s | 7 | 0.0111 $ |
| session_famille_gamelles | ✅ | 6 | 68 s | 17 | 0.0605 $ |
| memoire_durable_vs_ponctuel | ✅ | 2 | 8 s | 4 | 0.0114 $ |
| relecture_refus_poisson | ✅ | 4 | 43 s | 13 | 0.0414 $ |
| etudiant_petit_budget | ✅ | 4 | 44 s | 13 | 0.0431 $ |
| courses_au_mois | ✅ | 4 | 52 s | 13 | 0.0464 $ |
| relecture_rattrapage | ✅ | 5 | 44 s | 17 | 0.0562 $ |

## onboarding_famille — Onboarding d'une famille de 4 avec une allergie à l'arachide, des gamelles et un airfryer (< 3 min, mémoire correcte)

- ✅ étape 2 : outil update_memory appelé — appelés : update_memory, update_memory
- ✅ étape 3 : outil update_memory appelé — appelés : update_memory, update_memory, finish_onboarding
- ✅ onboarding terminé
- ✅ bloc finish_onboarding affiché
- ✅ mémoire foyer contient « 2 enfants » — valeurs : 2 adultes et 2 enfants (6 et 9 ans) (origine : live)
- ✅ mémoire allergie contient « arachide » — valeurs : arachide (origine : live)
- ✅ mémoire ustensile contient « airfryer » — valeurs : four | micro-ondes | airfryer (origine : live)
- ✅ mémoire frequence_courses contient « semaine » — valeurs : toutes les semaines (le samedi) (origine : live)
- ✅ temps IA total ≤ 180 s — 15 s
- ✅ mémoire habitude contient « gamelle » — valeurs : gamelles le midi au travail pour les parents (origine : live)
- ✅ mémoire contrainte contient « 30 » — valeurs : 30 minutes max en semaine (origine : live)

<details><summary>Déroulé</summary>

```
1. [nouvelle session onboarding, 7 jours] IA : Bienvenue sur Recipe2shop ! Je suis ravi de t'aider à organiser tes repas et tes courses. Pour commencer, dis-moi : vous êtes combien à la maison (adultes, enfants) ? Et est-ce qu'il y a des allergies ou un régime par…
2. MOI : Salut ! On est 4 à la maison : mon mari, moi et nos deux enfants de 6 et 9 ans. Le plus petit est allergique à l'arachide. (4.7 s, outils : update_memory, update_memory)
   IA : C'est bien noté pour les enfants et surtout l'allergie à l'arachide, je ferai très attention dans les recettes. À quel rythme as-tu l'habitude de faire les courses (chaque semaine, toutes les deux semaines...) ? Et cô…
3. MOI : On fait les courses toutes les semaines, le samedi. Mon mari et moi on prend une gamelle le midi au travail. (5.4 s, outils : update_memory, update_memory, finish_onboarding)
   IA : C'est parfait, je pourrai prévoir des portions en plus le soir pour vos déjeuners du lendemain ! J'ai déjà l'essentiel pour commencer à te proposer de chouettes idées. Tu peux cliquer sur « Terminer » dès maintenant s…
4. MOI : On a un four, un micro-ondes, et je viens de m'acheter un airfryer ! Je cuisine pas mal, mais en semaine j'ai 30 minutes max. (4.9 s, outils : update_memory, update_memory, update_memory, update_memory, finish_onboarding)
   IA : Génial, le airfryer va être un vrai allié pour tenir les 30 minutes les soirs de semaine ! Est-ce qu'il y a des ingrédients ou des plats que vous adorez particulièrement, ou au contraire des choses à bannir absolument…
5. [Terminer l'onboarding]
```
</details>

## session_famille_gamelles — Session complète d'une famille de 4 (allergie arachide, gamelles) : frigo → repas → recettes → liste (< 5 min)

- ✅ étape 1 : réponse contient /repas|dîners|diners|midi|soir/ — Salut ! C'est parti pour organiser les repas de la semaine. Pour bien démarrer : - Qu'est-ce qu'il te reste dans le frigo et les placards que tu aimerais utiliser ? - Combien de repas doit-on prévoir pour ces 7 jours …
- ✅ étape 1 : réponse ne contient pas /allerg|combien (êtes|etes)-vous|combien de personnes|vous êtes combien/ — Salut ! C'est parti pour organiser les repas de la semaine. Pour bien démarrer : - Qu'est-ce qu'il te reste dans le frigo et les placards que tu aimerais utiliser ? - Combien de repas doit-on prévoir pour ces 7 jours …
- ✅ étape 2 : outil set_meal_plan appelé — appelés : set_inventory, set_meal_plan, propose_recipe
- ✅ étape 2 : outil set_inventory appelé — appelés : set_inventory, set_meal_plan, propose_recipe
- ✅ étape 2 : outil propose_recipe appelé — appelés : set_inventory, set_meal_plan, propose_recipe
- ✅ étape 4 : outil generate_shopping_list appelé — appelés : generate_shopping_list
- ✅ liste principale générée et non vide — 18 articles, 5 rayons, déjà chez toi : carotte, riz
- ✅ aucune recette avec : arachide, cacahuete, cacahuète
- ✅ erreurs de génération de recette ≤ 1 — 0
- ✅ temps IA total ≤ 300 s — 68 s
- ✅ repas couverts ≥ repas prévus — 8 / 8
- ✅ inventaire « riz » déduit de la liste — déduits : carotte, riz, œuf
- ✅ inventaire « oeuf » déduit de la liste — déduits : carotte, riz, œuf
- ✅ erreurs de génération de recette ≤ 0 — 0

<details><summary>Déroulé</summary>

```
1. [nouvelle session shopping, 7 jours] IA : Salut ! C'est parti pour organiser les repas de la semaine. Pour bien démarrer : - Qu'est-ce qu'il te reste dans le frigo et les placards que tu aimerais utiliser ? - Combien de repas doit-on prévoir pour ces 7 jours …
2. MOI : Il me reste du riz, 6 oeufs et des carottes. Cette semaine il faut 5 dîners pour nous 4 et 3 gamelles pour le midi. Tu peux me proposer une première recette ? (13.5 s, outils : set_inventory, set_meal_plan, propose_recipe)
   IA : C'est bien noté : 8 repas à prévoir au total (5 dîners et 3 gamelles) et on utilise tes réserves. Pour commencer, je te propose un riz sauté aux carottes et œufs, prêt en moins de 30 minutes, parfait pour un dîner en …
3. ✅ « Riz sauté aux carottes et œufs » (11.0 s, outils : propose_recipe)
   IA : Super, c'est validé ! Pour la suite, je te propose un poulet rôti express avec pommes de terre et haricots verts à l'airfryer, rapide et idéal pour un dîner et une gamelle. Est-ce que ça te tente ?
3. ✅ « Poulet rôti express et légumes à l'airfryer » (14.9 s, outils : propose_recipe)
   IA : Parfait, ça nous fait 4 repas couverts sur les 8 prévus ! Pour continuer, je te propose un plat rapide et convivial qui plaît toujours aux enfants : des pâtes au thon, sauce tomate maison et mozzarella fondante, parfa…
3. ✅ « Pâtes au thon, tomates et mozzarella » (18.5 s, outils : propose_recipe)
   IA : Top, nous voilà à 6 repas sur les 8 ! Pour boucler les 2 derniers repas (un dîner et une gamelle), que dirais-tu d'une quiche express au jambon accompagnée d'une salade verte ? C'est simple, rapide et parfait à réchau…
3. ✅ « Quiche express au jambon et salade verte » (6.4 s, outils : generate_shopping_list)
   IA : Et voilà, tous tes repas pour la semaine sont calés ! J'ai généré ta liste de courses avec tous les ingrédients nécessaires pour ces recettes, en déduisant ce que tu as déjà dans tes placards. Dis-moi si tu veux y ajo…
4. MOI : Parfait, fais-moi la liste de courses. (3.8 s, outils : generate_shopping_list)
   IA : Voici ta liste de courses avec les ingrédients nécessaires pour la semaine, rangés par rayon et en tenant compte de ce que tu as déjà. Tu as besoin d'y ajouter des basiques ou des produits du quotidien ?
```
</details>

## memoire_durable_vs_ponctuel — La mémoire retient l'airfryer (durable) mais pas « ce soir pas de viande » (ponctuel)

- ✅ étape 2 : outil update_memory NON appelé — appelés : 
- ✅ étape 3 : outil update_memory appelé — appelés : update_memory
- ✅ mémoire ustensile contient « airfryer » — valeurs : four | airfryer (origine : live)
- ✅ mémoire naime_pas ne contient pas « viande » — valeurs : (aucune)
- ✅ mémoire regime ne contient pas « vegetarien » — valeurs : (aucune)
- ✅ mémoire contrainte ne contient pas « viande » — valeurs : (aucune)

<details><summary>Déroulé</summary>

```
1. [nouvelle session shopping, 7 jours] IA : Salut ! C'est parti pour organiser tes repas de la semaine. Pour bien démarrer : - Qu'est-ce qu'il te reste dans le frigo et les placards que tu aimerais utiliser ? - Combien de repas doit-on prévoir pour ces 7 jours …
2. MOI : Ce soir pas de viande, on a mangé un gros burger ce midi. (2.4 s, outils : —)
   IA : C'est bien noté, on part sur du végétarien et léger pour ce soir ! Pour que je puisse organiser la suite de la semaine : - Combien de repas prévois-tu au total sur les 7 jours (midis, soirs, gamelles) ? - As-tu des in…
3. MOI : Au fait, je viens d'acheter un airfryer ! (5.4 s, outils : update_memory)
   IA : Top pour l'airfryer, ça va être super pratique et rapide pour plein de recettes ! Dis-moi, combien de repas aimerais-tu qu'on planifie pour la semaine (midis, soirs, gamelles), et qu'as-tu déjà dans tes placards ou to…
```
</details>

## relecture_refus_poisson — Refus répétés du poisson → « n'aime pas le poisson » retenu (en direct ou par la relecture de fin de session)

- ✅ étape 2 : outil propose_recipe appelé — appelés : set_meal_plan, propose_recipe
- ✅ étape 3 : une recette est en attente
- ✅ étape 5 : une recette est en attente
- ✅ mémoire naime_pas contient « poisson » — valeurs : poisson (origine : live)

<details><summary>Déroulé</summary>

```
1. [nouvelle session shopping, 7 jours] IA : Salut ! Prêt à organiser les repas pour les 7 prochains jours. Pour qu'on démarre bien, dis-moi : - Combien de repas prévois-tu de cuisiner cette semaine (midis, soirs, gamelles, sorties éventuelles) ? - Ce qu'il te r…
2. MOI : Cette semaine 4 dîners. Propose-moi un plat avec du saumon. (12.4 s, outils : set_meal_plan, propose_recipe)
   IA : C'est noté pour les 4 dîners. Comme le saumon frais se consomme vite, c'est parfait pour commencer la semaine ! Voici une recette simple et saine au four : des pavés de saumon rôtis avec leurs petits légumes. Qu'en pe…
3. 🔄 « Pavés de saumon rôtis et légumes au four » (bof, le poisson c'est pas trop notre truc en fait) (12.1 s, outils : update_memory, propose_recipe)
   IA : Message bien reçu, on oublie le poisson ! Je te propose à la place un bon gratin réconfortant : des gnocchis fondants avec des dés de poulet et de la mozzarella gratinée au four. Est-ce que ça te tente ?
4. MOI : Et un plat avec du cabillaud, tu crois que ça passerait ? (2.8 s, outils : —)
   IA : Le cabillaud a un goût beaucoup plus doux et moins marqué que le saumon, mais ça reste du poisson blanc. Si vous n'êtes vraiment pas amateurs de poisson, vous risquez de ne pas vous régaler. On peut tenter un dos de c…
5. 🔄 « Gratin de gnocchis au poulet et mozzarella » (non, vraiment, pas de poisson) (15.3 s, outils : propose_recipe)
   IA : C'est bien noté, zéro poisson au menu ! Je te propose à la place un curry doux de poulet mijoté au lait de coco avec des légumes fondants et du riz basmati. Un plat réconfortant et sans la moindre trace marine. Ça te …
6. [nouvelle session → relecture de la précédente : faite par le worker]
```
</details>

## etudiant_petit_budget — Étudiant débutant, petit budget, sans four : recettes simples, valides, sans four

- ✅ étape 2 : outil set_meal_plan appelé — appelés : set_meal_plan, set_inventory, propose_recipe
- ✅ erreurs de génération de recette ≤ 1 — 0
- ✅ difficulté des recettes : easy
- ✅ erreurs de génération de recette ≤ 0 — 0

<details><summary>Déroulé</summary>

```
1. [nouvelle session shopping, 7 jours] IA : Salut ! C'est parti pour organiser tes repas de la semaine. Dis-moi : - Qu'est-ce qu'il te reste dans ton frigo et tes placards ? - Combien de repas tu souhaites prévoir pour ces 7 jours (midi, soir, gamelles) ? - As-…
2. MOI : J'ai juste des pâtes et du riz. Il me faut 4 dîners cette semaine, avec un budget serré. Propose-moi un premier plat. (14.3 s, outils : set_meal_plan, set_inventory, propose_recipe)
   IA : C'est bien noté pour tes 4 dîners et ton stock de pâtes et riz ! Pour commencer, je te propose un riz sauté aux petits légumes et œuf : très économique, rapide à faire à la poêle et parfait pour utiliser ton riz. Qu'e…
3. ✅ « Riz sauté aux petits légumes et œuf » (10.4 s, outils : propose_recipe)
   IA : Super, c'est validé ! Pour le deuxième dîner, je te propose des pâtes au thon et sauce tomate express : très économique, prêt en 10 minutes avec tes pâtes du placard. Est-ce que ça te tente ?
3. ✅ « Pâtes au thon et sauce tomate express » (9.0 s, outils : propose_recipe)
   IA : Et de deux ! Pour le troisième dîner, je te propose une omelette rustique aux pommes de terre : ultra simple à la poêle, nourrissante et très bon marché. Qu'en dis-tu ?
3. ✅ « Omelette rustique aux pommes de terre » (10.3 s, outils : propose_recipe)
   IA : C'est noté ! Il nous reste un dernier dîner à choisir pour boucler la semaine. Je te propose un curry express de pois chiches à la tomate : un plat chaud, végétarien, très économique et rapide à mijoter sur tes plaque…
```
</details>

## courses_au_mois — Courses au mois : période de 30 jours, priorité au batch cooking / congélation

- ✅ étape 1 : réponse contient /mois|30|semaines/ — Salut ! C'est parti pour organiser tes repas et tes courses pour le mois qui vient. Pour qu'on prépare ça au mieux, dis-moi : - Ce qu'il te reste dans les placards, le frigo ou le congélateur et que tu aimerais utilis…
- ✅ étape 2 : outil set_meal_plan appelé — appelés : set_meal_plan, propose_recipe
- ✅ période de 30 jours — 30
- ✅ erreurs de génération de recette ≤ 1 — 0
- ✅ au moins une recette taguée congelable / batch_cooking — Chili con carne mijoté ; Curry doux de poulet au lait de coco ; Gratin de pennes au saumon et épinards ; Dhal de lentilles corail et patates douces

<details><summary>Déroulé</summary>

```
1. [nouvelle session shopping, 30 jours] IA : Salut ! C'est parti pour organiser tes repas et tes courses pour le mois qui vient. Pour qu'on prépare ça au mieux, dis-moi : - Ce qu'il te reste dans les placards, le frigo ou le congélateur et que tu aimerais utilis…
2. MOI : C'est la grosse course du mois : il faut 20 dîners, on a un grand congélateur. Propose-moi une première recette. (13.7 s, outils : set_meal_plan, propose_recipe)
   IA : C'est noté pour les 20 dîners du mois ! On va miser sur des plats réconfortants et faciles à congeler en portions. Pour commencer, je te propose un chili con carne mijoté qui couvre 2 dîners et se congèle parfaitement…
3. ✅ « Chili con carne mijoté » (13.8 s, outils : propose_recipe)
   IA : Super, c'est enregistré ! Il nous reste 18 repas à prévoir. Pour continuer sur des plats simples et parfaits pour le congélateur, je te propose un curry doux de poulet au lait de coco pour 2 dîners. Qu'en penses-tu ?
3. ✅ « Curry doux de poulet au lait de coco » (10.7 s, outils : propose_recipe)
   IA : Parfait, voilà 4 repas de calés sur les 20 ! Pour la suite, je te propose un gratin de pennes au saumon et aux épinards, gourmand et très facile à congeler en portions pour 2 dîners. Qu'en dis-tu ?
3. ✅ « Gratin de pennes au saumon et épinards » (13.4 s, outils : propose_recipe)
   IA : Et de 6 repas couverts ! Pour varier un peu avec un plat végétarien réconfortant qui se congèle très bien, je te propose un dhal de lentilles corail et patates douces pour 2 dîners. Qu'en penses-tu ?
```
</details>

## relecture_rattrapage — La relecture de fin de session rattrape ce que l'IA n'a pas retenu en direct (update_memory désactivé pendant la conversation)

- ✅ étape 3 : une recette est en attente
- ✅ étape 5 : une recette est en attente
- ✅ mémoire ustensile contient « airfryer » (retenu par la relecture : batch) — valeurs : four | airfryer (origine : batch)
- ✅ mémoire naime_pas contient « poisson » (retenu par la relecture : batch) — valeurs : poisson (origine : batch)
- ✅ mémoire ustensile ne contient pas « poele » — valeurs : four | airfryer

<details><summary>Déroulé</summary>

```
1. [nouvelle session shopping, 7 jours] IA : Salut ! C'est parti pour organiser tes repas de la semaine. Pour bien démarrer : - Qu'est-ce qu'il te reste dans le frigo ou les placards que tu aimerais utiliser ? - Combien de repas doit-on prévoir pour ces prochain…
2. MOI : Je viens de m'acheter un airfryer, et ma poêle a rendu l'âme hier. Cette semaine 4 dîners. Propose-moi un plat avec du saumon. (9.9 s, outils : set_meal_plan, propose_recipe)
   IA : C'est bien noté pour tes 4 dîners et tes équipements de cuisine ! Pour commencer la semaine avec du poisson bien frais, je te propose un saumon rôti accompagné de légumes, parfait à cuire au airfryer. Qu'en penses-tu ?
3. 🔄 « Saumon et légumes rôtis au four » (non merci, on n'aime pas le poisson à la maison) (10.4 s, outils : propose_recipe)
   IA : C'est bien noté, pas de poisson ! Je te propose des cuisses de poulet dorées et croustillantes accompagnées de petits légumes rôtis, parfaites à préparer au airfryer ou au four. Dis-moi si cette idée te plaît !
4. MOI : Et avec des crevettes, ça donnerait quoi ? (10.3 s, outils : record_recipe_decision, propose_recipe)
   IA : Voici une plaque de crevettes épicées et petits légumes rôtis, très simple et rapide à faire au airfryer ou au four sans utiliser de poêle. Qu'en dis-tu pour un premier dîner ?
5. 🔄 « Plaque de crevettes et légumes rôtis » (non, pas de poisson ni de fruits de mer, on n'aime vraiment pas ça) (10.1 s, outils : propose_recipe)
   IA : C'est bien noté : zéro poisson et zéro fruits de mer, promis ! Pour changer complètement, je te propose un gratin réconfortant de gnocchis à la tomate et mozzarella fondante, très facile à dorer au four ou au airfryer…
6. MOI : Ce soir on mange chez ma mère, donc pas de dîner à prévoir aujourd'hui. (3.7 s, outils : set_meal_plan)
   IA : C'est noté, on passe donc à 3 dîners à prévoir pour cette semaine ! Pour le gratin de gnocchis à la tomate et mozzarella proposé juste avant, tu veux qu'on le garde pour demain soir, ou tu préfères partir sur autre ch…
7. [nouvelle session → relecture de la précédente : faite par le worker]
```
</details>
