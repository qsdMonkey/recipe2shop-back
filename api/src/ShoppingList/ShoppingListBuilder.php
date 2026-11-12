<?php

declare(strict_types=1);

namespace App\ShoppingList;

use App\Entity\Ingredient;
use App\Entity\InventoryItem;
use App\Entity\Recipe;
use App\Entity\SessionRecipeStatus;
use App\Entity\ShoppingSession;
use App\Ingredient\IngredientMatcher;
use App\Recipe\WeightRule;
use App\Repository\SessionRecipeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Agrégation déterministe de la liste principale (fiche 04) — « l'IA génère, le code calcule » :
 * 1. regroupement des ingrédients des recettes acceptées par ingrédient du référentiel ;
 * 2. conversion vers une unité commune (g↔kg, ml↔cl↔l, c. à s.→ml, pièce→g via le poids moyen : règle des poids) ;
 * 3. déduction de l'inventaire déclaré ;
 * 4. arrondi à l'achat. Les produits de base sont repérés (affichés à part par la présentation).
 */
final class ShoppingListBuilder
{
    public function __construct(
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly IngredientMatcher $matcher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function hasAcceptedRecipes(ShoppingSession $session): bool
    {
        foreach ($this->sessionRecipes->findForSession($session) as $sr) {
            if (SessionRecipeStatus::Accepted === $sr->getStatus()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<BuiltItem> */
    public function build(ShoppingSession $session): array
    {
        /** @var array<int, array{ingredient: Ingredient, parts: list<array{recipe: Recipe, quantity: ?float, unit: ?string, optional: bool}>}> $groups */
        $groups = [];
        foreach ($this->sessionRecipes->findForSession($session) as $sr) {
            if (SessionRecipeStatus::Accepted !== $sr->getStatus()) {
                continue;
            }
            $recipe = $sr->getRecipe();
            foreach ($recipe->getIngredients() as $ri) {
                $ingredient = $ri->getIngredient();
                if (null === $ingredient) {
                    $ingredient = $this->matcher->findOrCreate($ri->getName(), $ri->getCategory());
                    $ri->linkIngredient($ingredient);
                    $this->em->flush(); // id de l'ingrédient (éventuellement créé)
                }
                $groups[(int) $ingredient->getId()]['ingredient'] = $ingredient;
                $groups[(int) $ingredient->getId()]['parts'][] = ['recipe' => $recipe, 'quantity' => $ri->getQuantity(), 'unit' => $ri->getUnit(), 'optional' => $ri->isOptional()];
            }
        }

        $inventory = $this->inventoryByIngredient($session);

        $items = [];
        foreach ($groups as $id => $group) {
            array_push($items, ...$this->aggregate($group['ingredient'], $group['parts'], $inventory[$id] ?? null));
        }

        return $items;
    }

    /**
     * @param list<array{recipe: Recipe, quantity: ?float, unit: ?string, optional: bool}> $parts
     * @param list<array{quantity: ?float, unit: ?string}>|null                             $stock
     *
     * @return list<BuiltItem>
     */
    private function aggregate(Ingredient $ingredient, array $parts, ?array $stock): array
    {
        // Totaux par dimension, dans l'unité de base.
        $totals = $sources = $sourceUnits = $optional = [];
        $withoutQuantity = [];
        foreach ($parts as $part) {
            $source = ['recipe' => $part['recipe'], 'quantity' => $part['quantity'], 'unit' => $part['unit']];
            if (null === $part['quantity'] || null === $part['unit']) {
                $withoutQuantity[] = $source;
                $optional['none'] = ($optional['none'] ?? true) && $part['optional'];
                continue;
            }
            [$dimension, $value] = $this->toDimension($ingredient, $part['quantity'], $part['unit']);
            $totals[$dimension] = ($totals[$dimension] ?? 0.0) + $value;
            $sources[$dimension][] = $source;
            $sourceUnits[$dimension][] = $part['unit'];
            $optional[$dimension] = ($optional[$dimension] ?? true) && $part['optional'];
        }

        // Inventaire : sans quantité = tout est déjà là ; sinon on déduit dans la même dimension.
        $fullyStocked = false;
        $stockByDimension = [];
        foreach ($stock ?? [] as $s) {
            if (null === $s['quantity'] || null === $s['unit']) {
                $fullyStocked = true;
                continue;
            }
            [$dimension, $value] = $this->toDimension($ingredient, $s['quantity'], $s['unit']);
            $stockByDimension[$dimension] = ($stockByDimension[$dimension] ?? 0.0) + $value;
        }

        $items = [];
        foreach ($totals as $dimension => $total) {
            $have = $fullyStocked ? $total : min($total, $stockByDimension[$dimension] ?? 0.0);
            $remaining = $total - $have;
            $deducted = null;
            if ($have > 0 && $remaining > 1e-9) {
                [$q, $u] = Units::forShopping($dimension, $have, $sourceUnits[$dimension]);
                $deducted = ['quantity' => $q, 'unit' => $u];
            }
            if ($remaining <= 1e-9) {
                $items[] = new BuiltItem($ingredient, null, null, $optional[$dimension], true, null, $sources[$dimension]);
                continue;
            }
            [$quantity, $unit] = Units::forShopping($dimension, $remaining, $sourceUnits[$dimension]);
            $items[] = new BuiltItem($ingredient, $quantity, $unit, $optional[$dimension], false, $deducted, $sources[$dimension]);
        }

        // « À volonté » (sel, poivre…) : une ligne sans quantité, seulement si rien n'a de quantité.
        if ([] !== $withoutQuantity) {
            if ([] === $items) {
                $items[] = new BuiltItem($ingredient, null, null, $optional['none'], $fullyStocked, null, $withoutQuantity);
            } else {
                $first = $items[0];
                $items[0] = new BuiltItem($first->ingredient, $first->quantity, $first->unit, $first->optional && $optional['none'], $first->coveredByInventory, $first->inventoryDeducted, [...$first->sources, ...$withoutQuantity]);
            }
        }

        return $items;
    }

    /**
     * Ramène une quantité dans sa dimension de base. Pièces ↔ grammes via le poids moyen, vers l'unité préférée
     * de l'ingrédient (ex. oignon : en pièces ; pommes de terre : en grammes).
     *
     * @return array{0: string, 1: float}
     */
    private function toDimension(Ingredient $ingredient, float $quantity, string $unit): array
    {
        $dimension = Units::dimension($unit);
        $value = Units::toBase($quantity, $unit);
        $weight = $ingredient->getAvgWeightPerPiece();
        $preferred = $ingredient->getDefaultUnit();

        // Herbes fraîches & co (vendues en botte) : « 2 pièces » ou « 1 c. à s. » de coriandre → 1 botte par recette.
        if ('botte' === $preferred && 'botte' !== $unit) {
            return ['botte', 1.0];
        }

        if (null !== $weight && $weight > 0) {
            // Règle des poids : les produits qui ne se comptent pas (carotte, courgette, viande…) sont toujours en g / kg,
            // même si une ancienne recette ou l'inventaire les donne en pièces.
            // (l'ail en gousses ou le jambon en tranches gardent leur unité de comptage).
            if (!WeightRule::pieceAllowed($ingredient->getName(), $ingredient->getCategory())) {
                if ('piece' === $unit) {
                    return ['mass', $value * $weight];                    // 3 carottes → 300 g
                }
                if ('piece' === $preferred) {
                    return [$dimension, $value];                          // 300 g de carotte : restent en grammes
                }
            }
        }

        if (null !== $weight && $weight > 0 && null !== $preferred) {
            $preferredDimension = Units::dimension($preferred);
            if ('mass' === $dimension && !\in_array($preferredDimension, ['mass', 'volume'], true)) {
                return [$preferredDimension, $value / $weight];           // 300 g d'oignon → 2 pièces
            }
            if ('mass' === $preferredDimension && !\in_array($dimension, ['mass', 'volume'], true) && $dimension === $this->countUnitOf($ingredient, $unit)) {
                return ['mass', $value * $weight];                        // 2 pommes de terre → 300 g
            }
        }

        return [$dimension, $value];
    }

    private function countUnitOf(Ingredient $ingredient, string $unit): string
    {
        return \in_array($unit, ['piece', 'gousse', 'tranche'], true) ? $unit : '';
    }

    /** @return array<int, list<array{quantity: ?float, unit: ?string}>> */
    private function inventoryByIngredient(ShoppingSession $session): array
    {
        $result = [];
        foreach ($this->em->getRepository(InventoryItem::class)->findBy(['session' => $session]) as $item) {
            $ingredient = $item->getIngredient() ?? $this->matcher->find($item->getLabel());
            if (null === $ingredient || null === $ingredient->getId()) {
                continue;
            }
            $result[(int) $ingredient->getId()][] = ['quantity' => $item->getQuantity(), 'unit' => $item->getUnit()];
        }

        return $result;
    }
}
