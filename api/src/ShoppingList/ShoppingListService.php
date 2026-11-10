<?php

declare(strict_types=1);

namespace App\ShoppingList;

use App\Entity\ShoppingItemOrigin;
use App\Entity\ShoppingList;
use App\Entity\ShoppingListItem;
use App\Entity\ShoppingListType;
use App\Entity\ShoppingSession;
use App\Http\ApiException;
use App\Ingredient\IngredientMatcher;
use App\Recipe\RecipeVocabulary;
use App\Repository\SessionRecipeRepository;
use App\Repository\ShoppingListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Listes de courses : création, régénération (garde ajouts et cases cochées), ajouts et modifications d'articles.
 * Ne flush pas : à la charge de l'appelant (transaction du tour ou contrôleur).
 */
final class ShoppingListService
{
    public function __construct(
        private readonly ShoppingListRepository $lists,
        private readonly ShoppingListBuilder $builder,
        private readonly IngredientMatcher $matcher,
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly EntityManagerInterface $em,
        #[Autowire('%app.user_timezone%')]
        private readonly string $userTimezone,
    ) {
    }

    /**
     * Liste main : créée ou régénérée depuis les recettes acceptées. top_up / event : nouvelle liste vide nommée.
     *
     * @throws ApiException 409 no_accepted_recipe
     */
    public function generate(ShoppingSession $session, ShoppingListType $type, ?string $name = null): ShoppingList
    {
        $name = null === $name || '' === trim($name) ? null : mb_substr(trim($name), 0, 100);

        if (ShoppingListType::Main !== $type) {
            $list = new ShoppingList($session, $type, $name ?? $this->defaultName($type));
            $this->em->persist($list);

            return $list;
        }

        $list = $this->lists->findMain($session);
        if (null === $list) {
            $list = new ShoppingList($session, ShoppingListType::Main, $name ?? $this->defaultName($type));
            $this->em->persist($list);
        } elseif (null !== $name) {
            $list->rename($name);
        }
        $this->regenerate($list);

        return $list;
    }

    /** @throws ApiException 409 not_main_list / no_accepted_recipe */
    public function regenerate(ShoppingList $list): void
    {
        if (ShoppingListType::Main !== $list->getType()) {
            throw new ApiException(409, 'not_main_list', 'Only the main list is computed from recipes.');
        }
        if (!$this->builder->hasAcceptedRecipes($list->getSession())) {
            throw new ApiException(409, 'no_accepted_recipe', 'No accepted recipe in this session yet.');
        }

        $checked = [];
        foreach ($list->getItems()->toArray() as $item) {
            if ($item->isFromRecipes()) {
                if ($item->isChecked() && null !== $item->getIngredient()) {
                    $checked[$item->getIngredient()->getId().'|'.($item->getUnit() ?? '')] = true;
                }
                $list->removeItem($item);
            }
        }

        $excluded = $list->getExcludedIngredientIds();
        foreach ($this->builder->build($list->getSession()) as $built) {
            if (\in_array($built->ingredient->getId(), $excluded, true)) {
                continue;
            }
            $item = new ShoppingListItem($list, ShoppingItemOrigin::Recipe, $built->ingredient->getName(), $built->quantity, $built->unit, $built->ingredient->getCategory(), $built->ingredient);
            $item->setComputed($built->optional, $built->ingredient->isBasic(), $built->coveredByInventory, $built->inventoryDeducted);
            $item->setChecked(isset($checked[$built->key()]));
            foreach ($built->sources as $source) {
                $item->addSource($source['recipe'], $source['quantity'], $source['unit']);
            }
            $list->addItem($item);
        }
        $list->markGenerated();
    }

    /**
     * Ajout manuel (app) ou par l'IA. Fusionne avec un article non calculé de même libellé et même unité.
     *
     * @return array{0: ShoppingListItem, 1: bool} l'article et true s'il a été fusionné
     */
    public function addItem(ShoppingList $list, ShoppingItemOrigin $origin, string $label, ?float $quantity, ?string $unit, ?string $category): array
    {
        $label = mb_substr(trim($label), 0, 100);
        $unit = null === $quantity ? null : ($unit ?? 'piece');
        $ingredient = $this->matcher->find($label);
        $category = null !== $category && \in_array($category, RecipeVocabulary::CATEGORIES, true) ? $category : ($ingredient?->getCategory() ?? 'autre');

        $normalized = IngredientMatcher::normalize($label);
        foreach ($list->getItems() as $item) {
            if (!$item->isFromRecipes() && IngredientMatcher::normalize($item->getLabel()) === $normalized && $item->getUnit() === $unit) {
                $sum = null === $quantity || null === $item->getQuantity() ? $item->getQuantity() ?? $quantity : $item->getQuantity() + $quantity;
                $item->edit($item->getLabel(), $sum, $unit, $item->getCategory());
                $item->setChecked(false);

                return [$item, true];
            }
        }

        $item = new ShoppingListItem($list, $origin, $label, $quantity, $unit, $category, $ingredient);
        $list->addItem($item);

        return [$item, false];
    }

    /**
     * @param array{checked?: bool, label?: string, quantity?: ?float, unit?: ?string, category?: string} $changes
     *
     * @throws ApiException 409 recipe_item_readonly
     */
    public function updateItem(ShoppingListItem $item, array $changes): void
    {
        $edits = array_intersect_key($changes, array_flip(['label', 'quantity', 'unit', 'category']));
        if ([] !== $edits) {
            if ($item->isFromRecipes()) {
                throw new ApiException(409, 'recipe_item_readonly', 'Only "checked" can be changed on an item computed from recipes.');
            }
            $quantity = \array_key_exists('quantity', $edits) ? $edits['quantity'] : $item->getQuantity();
            $unit = \array_key_exists('unit', $edits) ? $edits['unit'] : $item->getUnit();
            $item->edit(
                isset($edits['label']) ? mb_substr(trim($edits['label']), 0, 100) : $item->getLabel(),
                $quantity,
                null === $quantity ? null : ($unit ?? 'piece'),
                $edits['category'] ?? $item->getCategory(),
            );
        }
        if (isset($changes['checked'])) {
            $item->setChecked($changes['checked']);
        }
    }

    /** Supprime un article ; un article calculé reste exclu aux régénérations suivantes. */
    public function deleteItem(ShoppingListItem $item): void
    {
        $list = $item->getList();
        if ($item->isFromRecipes() && null !== $item->getIngredient()) {
            $list->exclude((int) $item->getIngredient()->getId());
        }
        $list->removeItem($item);
    }

    /** @return array{item_count: int, checked_count: int} articles à acheter (hors produits de base et « déjà chez toi ») */
    public function counts(ShoppingList $list): array
    {
        $count = $checked = 0;
        foreach ($list->getItems() as $item) {
            if ($item->isBasic() || $item->isCoveredByInventory()) {
                continue;
            }
            ++$count;
            $checked += $item->isChecked() ? 1 : 0;
        }

        return ['item_count' => $count, 'checked_count' => $checked];
    }

    /** Liste main calculée avant une décision sur une recette ou un changement d'inventaire. */
    public function isOutdated(ShoppingList $list): bool
    {
        return ShoppingListType::Main === $list->getType()
            && null !== $list->getGeneratedAt()
            && $list->getGeneratedFromVersion() < $list->getSession()->getListInputsVersion();
    }

    private function defaultName(ShoppingListType $type): string
    {
        $date = (new \DateTimeImmutable('now', new \DateTimeZone($this->userTimezone)))->format('d/m');

        return match ($type) {
            ShoppingListType::Main => 'Courses de la semaine',
            ShoppingListType::TopUp => 'Appoint du '.$date,
            ShoppingListType::Event => 'Fête du '.$date,
        };
    }
}
