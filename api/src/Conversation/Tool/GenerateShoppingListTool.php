<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\ShoppingListType;
use App\Http\ApiException;
use App\Presenter\ShoppingListPresenter;
use App\ShoppingList\ShoppingListService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * generate_shopping_list({type, name?}) : l'IA déclenche, le code calcule (agrégation déterministe).
 */
final class GenerateShoppingListTool implements ToolHandlerInterface
{
    use ShoppingToolTrait;

    public function __construct(
        private readonly ShoppingListService $service,
        private readonly ShoppingListPresenter $presenter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'generate_shopping_list';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Crée une liste de courses, affichée en carte. main : la liste principale, CALCULÉE PAR L\'APPLICATION depuis les recettes acceptées '
                .'(quantités additionnées, inventaire déduit) ; rappelle-la pour la mettre à jour après un changement de recettes. '
                .'top_up (appoint) ou event (fête) : crée une liste vide nommée, à remplir ensuite avec add_list_items.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['main', 'top_up', 'event']],
                    'name' => ['type' => 'string', 'description' => 'Nom court (ex. « Apéro samedi (10 pers.) »). Facultatif.'],
                ],
                'required' => ['type'],
            ],
        ];
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $type = ShoppingListType::tryFrom(\is_string($args['type'] ?? null) ? $args['type'] : '');
        if (null === $type) {
            return ToolResult::error('"type" doit valoir main, top_up ou event.');
        }

        try {
            $list = $this->service->generate($context->session, $type, \is_string($args['name'] ?? null) ? $args['name'] : null);
            $this->em->flush();
        } catch (ApiException $e) {
            return ToolResult::error('no_accepted_recipe' === $e->errorCode
                ? 'Aucune recette acceptée : propose et fais valider des recettes avant de générer la liste principale.'
                : $e->getMessage());
        }

        $detail = $this->presenter->detail($list);
        $bySection = [];
        foreach ($detail['sections'] as $section) {
            $bySection[$section['category']] = \count($section['items']);
        }

        return ToolResult::ok(
            [
                'status' => 'ok',
                'list_id' => $list->getId(),
                'type' => $type->value,
                'name' => $list->getName(),
                'items_to_buy' => $detail['item_count'],
                'items_by_aisle' => $bySection,
                'already_have' => array_column($detail['already_have'], 'label'),
                'note' => ShoppingListType::Main === $type
                    ? 'La carte de la liste est affichée : n\'énumère pas les articles, résume en une phrase.'
                    : 'Liste vide créée : ajoute les articles avec add_list_items(list_id, items).',
            ],
            [['type' => 'shopping_list', 'list_id' => $list->getId()]],
        );
    }
}
