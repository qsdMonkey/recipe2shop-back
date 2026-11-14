<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Memory\MemoryManager;

/**
 * Portions de référence par adulte et par repas, en cru (demande de Pierre, 2026-11-14 : « un plat pour 2, je me
 * retrouve pour 4 »). « L'IA génère, le code calcule » : le total d'une famille dans une recette est rapporté au nombre
 * de portions (servings × meals_covered : les quantités d'une recette couvrent tous ses repas, comme la liste de courses).
 *
 * - Familles « base » (féculents, pommes de terre, viande, poisson, haché, légumineuses) : plage contrôlée dans les deux
 *   sens (erreur renvoyée à l'IA) ; un EXCÈS est corrigé par le code si l'IA n'y arrive pas (jamais un manque).
 * - Familles « garniture » (légumes, fromage, charcuterie, œufs, crème) : seul l'excès est signalé à l'IA, jamais corrigé
 *   par le code (une tartiflette ou une ratatouille sont légitimes).
 */
final class PortionRules
{
    /** Tolérance autour de la plage avant de signaler une erreur : [0,7 × min ; 1,3 × max]. */
    public const LOW_TOLERANCE = 0.7;
    public const HIGH_TOLERANCE = 1.3;

    /**
     * Sous 30 % du minimum, un ingrédient « base » est un simple appoint (vermicelles dans une soupe, lardons…) :
     * pas d'erreur de portion trop petite.
     */
    public const GARNISH_RATIO = 0.3;

    /** @var array<string, array{label: string, min: float, max: float, unit: string, base: bool}> */
    public const FAMILIES = [
        'feculent' => ['label' => 'féculents secs (pâtes, riz, semoule, lentilles…)', 'min' => 70, 'max' => 100, 'unit' => 'g', 'base' => true],
        'feculent_frais' => ['label' => 'féculents frais (gnocchis, pâtes fraîches)', 'min' => 150, 'max' => 200, 'unit' => 'g', 'base' => true],
        'pomme_de_terre' => ['label' => 'pommes de terre', 'min' => 200, 'max' => 250, 'unit' => 'g', 'base' => true],
        'viande' => ['label' => 'viande, volaille, poisson (sans os)', 'min' => 110, 'max' => 150, 'unit' => 'g', 'base' => true],
        'viande_os' => ['label' => 'viande avec os (cuisse de poulet…)', 'min' => 200, 'max' => 300, 'unit' => 'g', 'base' => true],
        'hache' => ['label' => 'viande hachée', 'min' => 100, 'max' => 125, 'unit' => 'g', 'base' => true],
        'legumineuse' => ['label' => 'légumineuses en conserve, égouttées', 'min' => 120, 'max' => 150, 'unit' => 'g', 'base' => true],
        'legume' => ['label' => 'légumes (total)', 'min' => 150, 'max' => 300, 'unit' => 'g', 'base' => false],
        'fromage' => ['label' => 'fromage', 'min' => 20, 'max' => 60, 'unit' => 'g', 'base' => false],
        'charcuterie' => ['label' => 'charcuterie (lardons, jambon, chorizo)', 'min' => 30, 'max' => 80, 'unit' => 'g', 'base' => false],
        'oeuf' => ['label' => 'œufs', 'min' => 1, 'max' => 2.5, 'unit' => 'œuf', 'base' => false],
        'creme' => ['label' => 'crème', 'min' => 30, 'max' => 60, 'unit' => 'ml', 'base' => false],
    ];

    /**
     * Familles des noms absents du référentiel (« penne », « blanc de poulet »…), par mots du nom normalisé.
     * Ordre important : le premier motif qui correspond l'emporte.
     *
     * @var array<string, string>
     */
    private const KEYWORDS = [
        '/\b(pates? fraiches?|gnocchis?|raviolis?|tortellinis?)\b/' => 'feculent_frais',
        '/\b(pates? (a pizza|feuilletee|brisee|sablee|de curry|d.arachide)|pain|creme de|sauce|vinaigre|farine)\b/' => '',
        '/\b(riz|pates?|spaghettis?|penne|pennes|fusillis?|linguines?|tagliatelles?|macaronis?|coquillettes?|farfalles?|rigatonis?|orzo|vermicelles?|nouilles?|semoule|couscous|quinoa|boulgour|polenta|lentilles?)\b/' => 'feculent',
        '/\b(pommes? de terre|patates?|grenailles?)\b/' => 'pomme_de_terre',
        '/\b(hache|steak hache|chair a saucisse|boulettes?)\b/' => 'hache',
        '/\b(cuisses?|pilons?|cotes?|poulet entier|souris d.agneau|jarret)\b/' => 'viande_os',
        '/\b(lardons?|jambon|chorizo|bacon|pancetta|saumon fume|coppa|speck)\b/' => 'charcuterie',
        '/\b(poulet|dinde|boeuf|veau|porc|agneau|canard|saumon|cabillaud|colin|merlu|lieu|thon|crevettes?|escalopes?|filets? de|blancs? de|paves? de|tofu|seitan|poisson|truite|dorade|bar|maquereau|calamars?|saint.jacques)\b/' => 'viande',
        '/\b(pois chiches?|haricots (rouges|blancs|noirs)|flageolets?)\b/' => 'legumineuse',
        '/\b(oeufs?)\b/' => 'oeuf',
        '/\b(creme fraiche|creme liquide|creme epaisse|creme entiere|creme legere)\b/' => 'creme',
        '/\b(fromage|emmental|gruyere|comte|parmesan|mozzarella|feta|chevre|cheddar|reblochon|ricotta|mascarpone|burrata|beaufort|roquefort|bleu)\b/' => 'fromage',
        '/\b(oignons?|echalotes?|poireaux?|carottes?|courgettes?|aubergines?|poivrons?|tomates?|concombres?|brocolis?|chou|choux|epinards?|haricots verts|petits pois|champignons?|butternut|potiron|courge|navets?|betteraves?|celeri|fenouil|asperges?|legumes?|mais|potimarron|panais|blettes?|artichauts?)\b/' => 'legume',
    ];

    public static function familyFromName(string $name): ?string
    {
        $normalized = MemoryManager::normalize($name);
        foreach (self::KEYWORDS as $pattern => $family) {
            if (1 === preg_match($pattern, $normalized)) {
                return '' === $family ? null : $family;
            }
        }

        return null;
    }

    public static function isFamily(?string $family): bool
    {
        return null !== $family && isset(self::FAMILIES[$family]);
    }
}
