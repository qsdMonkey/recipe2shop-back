<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\ShoppingItemOrigin;
use App\Recipe\RecipeVocabulary;
use App\Repository\ShoppingListRepository;
use App\ShoppingList\ShoppingListService;
use Doctrine\ORM\EntityManagerInterface;

/** add_list_items({list_id, items}) : « ajoute du PQ », liste d'appoint ou de fête. */
final class AddListItemsTool implements ToolHandlerInterface
{
    use ShoppingToolTrait;

    private const MAX_ITEMS = 60;

    public function __construct(
        private readonly ShoppingListRepository $lists,
        private readonly ShoppingListService $service,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'add_list_items';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Ajoute des articles à une liste de courses de la session (list_id dans l\'état de la session, ou renvoyé par generate_shopping_list).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'list_id' => ['type' => 'integer'],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string', 'description' => 'Nom simple (« lait », « papier toilette »).'],
                                'quantity' => ['type' => 'number', 'description' => 'Seulement si l\'utilisateur la donne ou si elle est utile (ex. quantités d\'un apéro). Pas pour « du PQ », « de la lessive ».'],
                                'unit' => ['type' => 'string', 'enum' => RecipeVocabulary::UNITS],
                                'category' => ['type' => 'string', 'enum' => RecipeVocabulary::CATEGORIES],
                            ],
                            'required' => ['label'],
                        ],
                    ],
                ],
                'required' => ['list_id', 'items'],
            ],
        ];
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $list = \is_int($args['list_id'] ?? null) ? $this->lists->find($args['list_id']) : null;
        if (null === $list || $list->getSession() !== $context->session) {
            return ToolResult::error('Liste introuvable dans cette session (vérifie list_id dans l\'état de la session).');
        }
        $items = $args['items'] ?? null;
        if (!\is_array($items) || [] === $items || \count($items) > self::MAX_ITEMS) {
            return ToolResult::error(\sprintf('"items" doit contenir entre 1 et %d articles.', self::MAX_ITEMS));
        }

        $added = [];
        foreach ($items as $i => $item) {
            $label = \is_array($item) && \is_string($item['label'] ?? null) ? trim($item['label']) : '';
            if ('' === $label || mb_strlen($label) > 100) {
                return ToolResult::error(\sprintf('items[%d].label est obligatoire (100 caractères max).', $i));
            }
            $unit = \is_string($item['unit'] ?? null) && \in_array($item['unit'], RecipeVocabulary::UNITS, true) ? $item['unit'] : null;
            $quantity = is_numeric($item['quantity'] ?? null) && $item['quantity'] > 0 ? (float) $item['quantity'] : null;
            $category = \is_string($item['category'] ?? null) ? $item['category'] : null;
            $this->service->addItem($list, ShoppingItemOrigin::Assistant, $label, $quantity, $unit, $category);
            $added[] = $label;
        }
        $this->em->flush();

        return ToolResult::ok(
            ['status' => 'ok', 'list_id' => $list->getId(), 'added' => $added] + $this->service->counts($list),
            [['type' => 'shopping_list', 'list_id' => $list->getId()]],
        );
    }
}
