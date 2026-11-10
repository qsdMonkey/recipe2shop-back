<?php

declare(strict_types=1);

namespace App\Ingredient;

use App\Entity\Ingredient;
use App\Memory\MemoryManager;
use App\Recipe\RecipeVocabulary;
use App\Repository\IngredientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Rattache un nom libre (« 2 oignons rouges », « Filet de poulet ») à un ingrédient du référentiel :
 * nom exact, alias, puis formes au singulier. Inconnu → nouvel ingrédient « to_review » (jamais d'erreur).
 */
final class IngredientMatcher implements ResetInterface
{
    /** @var array<string, Ingredient>|null nom ou alias normalisé → ingrédient */
    private ?array $index = null;

    public function __construct(
        private readonly IngredientRepository $ingredients,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public static function normalize(string $name): string
    {
        $name = MemoryManager::normalize($name);
        $name = (string) preg_replace('/\s*\(.*?\)\s*/u', ' ', $name);            // « (facultatif) »
        $name = (string) preg_replace('/^(de la|du|des|de l|d|le|la|les|l|un|une)\s+/u', '', $name);

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    public function find(string $name): ?Ingredient
    {
        $index = $this->index();
        foreach (self::candidates(self::normalize($name)) as $candidate) {
            if (isset($index[$candidate])) {
                return $index[$candidate];
            }
        }

        return null;
    }

    /** Trouve ou crée (à vérifier) l'ingrédient. Ne flush pas. */
    public function findOrCreate(string $name, string $category): Ingredient
    {
        if (null !== $ingredient = $this->find($name)) {
            return $ingredient;
        }

        $normalized = self::candidates(self::normalize($name))[1] ?? self::normalize($name); // forme au singulier
        $ingredient = new Ingredient(
            name: mb_substr(mb_strtolower(trim($name)), 0, 100),
            normalizedName: mb_substr($normalized, 0, 100),
            category: \in_array($category, RecipeVocabulary::CATEGORIES, true) ? $category : 'autre',
            defaultUnit: null,
            shelfLifeDays: null,
            avgWeightPerPiece: null,
            basic: false,
            toReview: true,
        );
        $this->em->persist($ingredient);
        $this->index[$ingredient->getNormalizedName()] = $ingredient;

        return $ingredient;
    }

    public function reset(): void
    {
        $this->index = null;
    }

    /**
     * Formes essayées dans l'ordre : telle quelle, puis au singulier (chaque mot). Pas de raccourci « X de Y » → « X »
     * (« jus de citron » n'est pas du « jus d'orange ») : un nom inconnu devient un ingrédient à vérifier.
     *
     * @return list<string>
     */
    private static function candidates(string $normalized): array
    {
        $forms = [$normalized];
        // Qualificatifs sans effet sur l'article à acheter : « pulpe de tomate en boîte », « gnocchis à poêler ».
        $stripped = trim((string) preg_replace('/ (en boite|en conserve|a poeler|a cuire|pret a cuire|bio|du commerce)$/u', '', $normalized));
        if ('' !== $stripped && $stripped !== $normalized) {
            $forms[] = $stripped;
        }

        $candidates = [];
        foreach ($forms as $form) {
            $candidates[] = $form;
            $candidates[] = implode(' ', array_map(self::singular(...), explode(' ', $form)));
        }

        return array_values(array_unique($candidates));
    }

    private static function singular(string $word): string
    {
        if (mb_strlen($word) <= 3) {
            return $word;
        }
        if (str_ends_with($word, 'eaux')) {
            return mb_substr($word, 0, -1);
        }
        if (preg_match('/[sx]$/u', $word) && !preg_match('/(ss|ais|ois|is|us|as)$/u', $word)) {
            return mb_substr($word, 0, -1);
        }

        return $word;
    }

    /** @return array<string, Ingredient> */
    private function index(): array
    {
        if (null === $this->index) {
            $this->index = [];
            foreach ($this->ingredients->findAll() as $ingredient) {
                foreach ([$ingredient->getNormalizedName(), ...$ingredient->getAliases()] as $key) {
                    $this->index[$key] ??= $ingredient;
                    $this->index[implode(' ', array_map(self::singular(...), explode(' ', $key)))] ??= $ingredient;
                }
            }
        }

        return $this->index;
    }
}
