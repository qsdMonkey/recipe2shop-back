<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\InventoryItem;
use App\Ingredient\IngredientMatcher;
use App\Recipe\RecipeVocabulary;
use Doctrine\ORM\EntityManagerInterface;

/**
 * set_inventory({items}) : ce que l'utilisateur a déjà. Remplace la liste complète (l'IA renvoie tout l'inventaire à jour).
 */
final class SetInventoryTool implements ToolHandlerInterface
{
    use ShoppingToolTrait;

    private const MAX_ITEMS = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IngredientMatcher $matcher,
    ) {
    }

    public function name(): string
    {
        return 'set_inventory';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Enregistre ce que l\'utilisateur a déjà (frigo, placards) et veut utiliser. Remplace l\'inventaire complet : '
                .'renvoie toujours la liste entière à jour, y compris ce qui était déjà déclaré.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string', 'description' => 'Nom simple, au singulier (« courgette », « riz »).'],
                                'quantity' => ['type' => 'number'],
                                'unit' => ['type' => 'string', 'enum' => RecipeVocabulary::UNITS],
                            ],
                            'required' => ['label'],
                        ],
                    ],
                ],
                'required' => ['items'],
            ],
        ];
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $items = $args['items'] ?? null;
        if (!\is_array($items) || \count($items) > self::MAX_ITEMS) {
            return ToolResult::error(\sprintf('"items" doit être une liste de %d éléments au plus.', self::MAX_ITEMS));
        }

        $clean = [];
        foreach ($items as $i => $item) {
            $label = \is_array($item) && \is_string($item['label'] ?? null) ? trim($item['label']) : '';
            if ('' === $label || mb_strlen($label) > 100) {
                return ToolResult::error(\sprintf('items[%d].label est obligatoire (100 caractères max).', $i));
            }
            $unit = \is_string($item['unit'] ?? null) ? $item['unit'] : null;
            if (null !== $unit && !\in_array($unit, RecipeVocabulary::UNITS, true)) {
                return ToolResult::error(\sprintf('items[%d].unit invalide.', $i), ['Unités possibles : '.implode(', ', RecipeVocabulary::UNITS)]);
            }
            $quantity = is_numeric($item['quantity'] ?? null) && $item['quantity'] > 0 ? (float) $item['quantity'] : null;
            // Une quantité sans unité (« 2 courgettes ») compte en pièces.
            $clean[] = [mb_strtolower($label), $quantity, null === $quantity ? null : ($unit ?? 'piece')];
        }

        $this->em->createQueryBuilder()->delete(InventoryItem::class, 'i')->where('i.session = :session')->setParameter('session', $context->session)->getQuery()->execute();
        foreach ($clean as [$label, $quantity, $unit]) {
            $item = new InventoryItem($context->session, $label, $quantity, $unit);
            $item->linkIngredient($this->matcher->find($label));
            $this->em->persist($item);
        }
        $context->session->bumpListInputsVersion();
        $this->em->flush();

        return ToolResult::ok(['status' => 'ok', 'items' => \count($clean)]);
    }
}
