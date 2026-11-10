<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\InventoryItem;
use App\Entity\SessionRecipeStatus;
use App\Entity\ShoppingSession;
use App\Repository\SessionRecipeRepository;
use App\Repository\ShoppingListRepository;
use App\Ingredient\IngredientMatcher;
use App\ShoppingList\ShoppingListService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * État d'une session de courses, lu en base (et non dans l'historique) : repas prévus, inventaire, recettes.
 */
final class SessionStateFormatter
{
    public function __construct(
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly EntityManagerInterface $em,
        private readonly ShoppingListRepository $shoppingLists,
        private readonly ShoppingListService $shoppingListService,
        private readonly IngredientMatcher $matcher,
    ) {
    }

    /** @return list<string> */
    public function lines(ShoppingSession $session): array
    {
        $lines = [];

        $planned = $session->getMealsPlanned();
        $covered = $this->sessionRecipes->mealsCovered($session);
        if (null === $planned) {
            $lines[] = '- Repas prévus : pas encore connus (demande-les, puis enregistre-les avec set_meal_plan).';
        } else {
            $details = $session->getMealPlan() ?? [];
            $parts = [];
            foreach (['dinners' => 'dîners', 'lunches' => 'déjeuners', 'lunchboxes' => 'gamelles'] as $key => $label) {
                if (isset($details[$key]) && (int) $details[$key] > 0) {
                    $parts[] = $details[$key].' '.$label;
                }
            }
            $detail = [] === $parts ? '' : ' ('.implode(', ', $parts).')';
            $notes = isset($details['notes']) && '' !== $details['notes'] ? ' — notes : '.$details['notes'] : '';
            $lines[] = \sprintf('- Repas prévus : %d%s%s.', $planned, $detail, $notes);
            $lines[] = \sprintf('- Repas couverts par les recettes acceptées : %d sur %d%s', $covered, $planned, $covered >= $planned ? ' → c\'est complet, ne propose plus de recette sauf si l\'utilisateur en redemande.' : '.');
        }

        $inventory = $this->em->getRepository(InventoryItem::class)->findBy(['session' => $session], ['id' => 'ASC']);
        $lines[] = '- Ce que l\'utilisateur a déjà (inventaire, set_inventory) : '.([] === $inventory ? 'rien de déclaré.' : implode(', ', array_map(self::formatInventory(...), $inventory)).'.');

        $accepted = $pending = $rejected = [];
        foreach ($this->sessionRecipes->findForSession($session) as $sr) {
            $recipe = $sr->getRecipe();
            $label = \sprintf('« %s » (recipe_id %d, %d repas%s)', $recipe->getTitle(), $recipe->getId(), $recipe->getMealsCovered(), null === $sr->getSuggestedDay() ? '' : ', jour '.$sr->getSuggestedDay());
            match ($sr->getStatus()) {
                SessionRecipeStatus::Accepted => $accepted[] = $label.($sr->isCooked() ? ' [déjà cuisinée]' : ''),
                SessionRecipeStatus::Proposed => $pending[] = $label,
                SessionRecipeStatus::Rejected => $rejected[] = $label.(null === $sr->getComment() ? '' : ' — « '.$sr->getComment().' »'),
            };
        }
        $lines[] = '- Recettes acceptées : '.([] === $accepted ? 'aucune.' : implode(' ; ', $accepted).'.');
        if ([] !== $pending) {
            $lines[] = '- Recette proposée, en attente de la réponse de l\'utilisateur : '.implode(' ; ', $pending).'. S\'il répond par écrit, enregistre sa décision avec record_recipe_decision avant toute nouvelle proposition.';
        }
        $lines[] = '- Recettes refusées (ne pas les reproposer, tenir compte des commentaires) : '.([] === $rejected ? 'aucune.' : implode(' ; ', $rejected).'.');

        $lists = [];
        foreach ($this->shoppingLists->findForSession($session) as $list) {
            $counts = $this->shoppingListService->counts($list);
            $lists[] = \sprintf(
                '%s « %s » (list_id %d, %d articles dont %d cochés%s)',
                $list->getType()->value,
                $list->getName(),
                $list->getId(),
                $counts['item_count'],
                $counts['checked_count'],
                $this->shoppingListService->isOutdated($list) ? ', PAS À JOUR : rappelle generate_shopping_list(main)' : '',
            );
        }
        $lines[] = '- Listes de courses : '.([] === $lists ? 'aucune.' : implode(' ; ', $lists).'.');

        $fragile = $this->fragileProducts($session);
        if ([] !== $fragile) {
            $lines[] = '- Durées de conservation indicatives (référentiel) des produits frais de la session : '.implode(', ', $fragile).'. Prévois-les en début de période.';
        }

        return $lines;
    }

    /** @return list<string> produits (recettes acceptées / en attente, inventaire) qui se gardent 7 jours ou moins */
    private function fragileProducts(ShoppingSession $session): array
    {
        $days = [];
        foreach ($this->sessionRecipes->findForSession($session) as $sr) {
            if (SessionRecipeStatus::Rejected === $sr->getStatus()) {
                continue;
            }
            foreach ($sr->getRecipe()->getIngredients() as $ri) {
                $ingredient = $ri->getIngredient() ?? $this->matcher->find($ri->getName());
                if (null !== $ingredient && null !== $ingredient->getShelfLifeDays() && $ingredient->getShelfLifeDays() <= 7) {
                    $days[$ingredient->getName()] = $ingredient->getShelfLifeDays();
                }
            }
        }
        foreach ($this->em->getRepository(InventoryItem::class)->findBy(['session' => $session]) as $item) {
            $ingredient = $item->getIngredient() ?? $this->matcher->find($item->getLabel());
            if (null !== $ingredient && null !== $ingredient->getShelfLifeDays() && $ingredient->getShelfLifeDays() <= 7) {
                $days[$ingredient->getName()] = $ingredient->getShelfLifeDays();
            }
        }
        asort($days);

        return array_map(static fn (string $name, int $d): string => \sprintf('%s %d j', $name, $d), array_keys($days), array_values($days));
    }

    private static function formatInventory(InventoryItem $item): string
    {
        if (null === $item->getQuantity()) {
            return $item->getLabel();
        }
        $quantity = rtrim(rtrim(number_format($item->getQuantity(), 2, '.', ''), '0'), '.');

        return \sprintf('%s (%s%s)', $item->getLabel(), $quantity, null === $item->getUnit() ? '' : ' '.$item->getUnit());
    }
}
