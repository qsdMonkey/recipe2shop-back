<?php

declare(strict_types=1);

namespace App\Ingredient;

use App\Memory\MemoryManager;
use App\Repository\IngredientRepository;

/**
 * Gemini écrit parfois les noms d'ingrédients sans accents (« tortilla de ble », « epices a fajitas »).
 * On restaure les accents mot par mot, uniquement sur un nom qui n'en contient AUCUN (un nom déjà accentué n'est
 * jamais modifié). Dictionnaire : mots accentués du référentiel + mots courants de cuisine ; « a » isolé → « à ».
 */
final class AccentRestorer
{
    /** Mots courants de cuisine (hors référentiel) et leur forme accentuée. */
    private const ACCENTED = [
        'epice' => 'épice', 'epices' => 'épices', 'epicee' => 'épicée', 'epicees' => 'épicées', 'hache' => 'haché',
        'hachee' => 'hachée', 'haches' => 'hachés', 'hachees' => 'hachées', 'rape' => 'râpé', 'rapee' => 'râpée',
        'rapes' => 'râpés', 'rapees' => 'râpées', 'fume' => 'fumé', 'fumee' => 'fumée', 'fumes' => 'fumés', 'fumees' => 'fumées',
        'seche' => 'séché', 'sechee' => 'séchée', 'seches' => 'séchés', 'sechees' => 'séchées', 'emince' => 'émincé',
        'emincee' => 'émincée', 'emincees' => 'émincées', 'eminces' => 'émincés', 'concasse' => 'concassé',
        'concassee' => 'concassée', 'concassees' => 'concassées', 'pele' => 'pelé', 'pelee' => 'pelée', 'pelees' => 'pelées',
        'grille' => 'grillé', 'grillee' => 'grillée', 'grillees' => 'grillées', 'roti' => 'rôti', 'rotie' => 'rôtie',
        'roties' => 'rôties', 'dore' => 'doré', 'doree' => 'dorée', 'sucre' => 'sucre', 'sucree' => 'sucrée', 'sucrees' => 'sucrées',
        'sale' => 'salé', 'salee' => 'salée', 'salees' => 'salées', 'creme' => 'crème', 'cremes' => 'crèmes',
        'fraiche' => 'fraîche', 'fraiches' => 'fraîches', 'entiere' => 'entière', 'entieres' => 'entières', 'legume' => 'légume',
        'legumes' => 'légumes', 'pate' => 'pâte', 'pates' => 'pâtes', 'puree' => 'purée', 'melange' => 'mélange',
        'preparation' => 'préparation', 'gelatine' => 'gélatine', 'cote' => 'côte', 'cotes' => 'côtes', 'cotelette' => 'côtelette',
        'cotelettes' => 'côtelettes', 'tete' => 'tête', 'cafe' => 'café', 'the' => 'thé', 'ble' => 'blé', 'mais' => 'maïs',
        'boeuf' => 'bœuf', 'oeuf' => 'œuf', 'oeufs' => 'œufs', 'celeri' => 'céleri', 'echalote' => 'échalote',
        'echalotes' => 'échalotes', 'peche' => 'pêche', 'peches' => 'pêches', 'mache' => 'mâche', 'sesame' => 'sésame',
        'vegetal' => 'végétal', 'vegetale' => 'végétale', 'vegetales' => 'végétales', 'vegetaux' => 'végétaux',
        'cereales' => 'céréales', 'decortique' => 'décortiqué', 'decortiquee' => 'décortiquée', 'decortiquees' => 'décortiquées',
        'egoutte' => 'égoutté', 'egouttes' => 'égouttés', 'egouttee' => 'égouttée', 'egouttees' => 'égouttées',
        'congele' => 'congelé', 'congelee' => 'congelée', 'congeles' => 'congelés', 'congelees' => 'congelées',
        'surgele' => 'surgelé', 'surgelee' => 'surgelée', 'surgeles' => 'surgelés', 'surgelees' => 'surgelées',
        'grattee' => 'grattée', 'citronne' => 'citronné', 'poelee' => 'poêlée', 'mi-cuit' => 'mi-cuit', 'pre-cuit' => 'pré-cuit',
        'precuit' => 'précuit', 'precuits' => 'précuits', 'precuite' => 'précuite', 'precuites' => 'précuites', 'saute' => 'sauté',
        'sautes' => 'sautés', 'genoise' => 'génoise', 'eclat' => 'éclat', 'eclats' => 'éclats', 'pepite' => 'pépite',
        'pepites' => 'pépites', 'praline' => 'praliné', 'gruyere' => 'gruyère', 'comte' => 'comté', 'chevre' => 'chèvre',
        'feuillete' => 'feuilleté', 'feuilletee' => 'feuilletée', 'brisee' => 'brisée', 'sablee' => 'sablée', 'levee' => 'levée',
        'ecrase' => 'écrasé', 'ecrasee' => 'écrasée', 'ecrases' => 'écrasés', 'ecrasees' => 'écrasées', 'tranchee' => 'tranchée',
        'reduit' => 'réduit', 'allege' => 'allégé', 'allegee' => 'allégée', 'demi-ecreme' => 'demi-écrémé', 'ecreme' => 'écrémé',
        'provencale' => 'provençale', 'mediterraneen' => 'méditerranéen', 'mediterraneenne' => 'méditerranéenne',
        'perle' => 'perlé', 'perles' => 'perlés', 'epais' => 'épais', 'epaisse' => 'épaisse', 'epaisses' => 'épaisses',
        'leger' => 'léger', 'legere' => 'légère', 'legers' => 'légers', 'legeres' => 'légères', 'cremeux' => 'crémeux',
        'cremeuse' => 'crémeuse', 'decoupe' => 'découpé', 'decoupee' => 'découpée', 'degraisse' => 'dégraissé',
        'desosse' => 'désossé', 'desossee' => 'désossée', 'marine' => 'mariné', 'marinee' => 'marinée', 'farci' => 'farci',
        'gratine' => 'gratiné', 'blanchi' => 'blanchi', 'ecossee' => 'écossée', 'ecosses' => 'écossés', 'equeute' => 'équeuté',
    ];

    /** @var array<string, string>|null mot sans accents → mot accentué */
    private ?array $dictionary = null;

    public function __construct(private readonly IngredientRepository $ingredients)
    {
    }

    public function restore(string $name): string
    {
        if (1 === preg_match('/[^\x00-\x7F]/', $name)) {
            return $name; // déjà accentué (ou non ASCII) : on ne touche à rien
        }
        $dictionary = $this->dictionary();

        $restored = (string) preg_replace_callback('/[A-Za-z]+(?:-[A-Za-z]+)*/', static function (array $m) use ($dictionary): string {
            $word = $m[0];
            $accented = $dictionary[strtolower($word)] ?? null;
            if (null === $accented) {
                return $word;
            }

            return ctype_upper($word[0]) ? mb_strtoupper(mb_substr($accented, 0, 1)).mb_substr($accented, 1) : $accented;
        }, $name);

        // « a » isolé entre deux mots : préposition « à » (« épices a fajitas », « pâte a pizza »).
        return (string) preg_replace('/(?<=\S) a (?=\S)/u', ' à ', $restored);
    }

    public function reset(): void
    {
        $this->dictionary = null;
    }

    /** @return array<string, string> */
    private function dictionary(): array
    {
        if (null === $this->dictionary) {
            $this->dictionary = self::ACCENTED;
            foreach ($this->ingredients->findBy(['toReview' => false]) as $ingredient) {
                foreach (preg_split('/[\s\']+/u', $ingredient->getName()) ?: [] as $word) {
                    if ('' !== $word && 1 === preg_match('/[^\x00-\x7F]/u', $word)) {
                        $this->dictionary[MemoryManager::normalize($word)] ??= mb_strtolower($word);
                    }
                }
            }
            // « sucre » (sans accent) est un vrai mot : ne pas le transformer.
            unset($this->dictionary['sucre']);
        }

        return $this->dictionary;
    }
}
