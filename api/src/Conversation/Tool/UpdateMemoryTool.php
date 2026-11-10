<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\MemoryCategory;
use App\Entity\MemoryOrigin;
use App\Entity\ShoppingSession;
use App\Memory\MemoryException;
use App\Memory\MemoryManager;
use App\Repository\UserMemoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * update_memory({action, category, value, old_value?, reason}) : mémoire longue, en direct pendant la conversation.
 * Chaque opération réussie produit une puce « 📝 Retenu / 🗑️ Oublié » (bloc memory) dans le message de l'IA.
 */
final class UpdateMemoryTool implements ToolHandlerInterface
{
    public function __construct(
        private readonly MemoryManager $memory,
        private readonly UserMemoryRepository $items,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'update_memory';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Met à jour la mémoire long terme de l\'utilisateur (ce qui sera encore vrai la semaine prochaine). '
                .'Une opération par appel. N\'enregistre jamais une info ponctuelle (« ce soir pas de viande »).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['add', 'update', 'delete'], 'description' => 'add : nouvelle info ; update : remplacer une valeur existante ; delete : oublier une valeur.'],
                    'category' => ['type' => 'string', 'enum' => MemoryCategory::values()],
                    'value' => ['type' => 'string', 'description' => 'add/update : la nouvelle valeur, courte et autonome (« airfryer », « 2 adultes et 1 enfant de 6 ans »). delete : la valeur à oublier, telle qu\'elle figure dans la mémoire.'],
                    'old_value' => ['type' => 'string', 'description' => 'update : la valeur actuelle à remplacer, telle qu\'elle figure dans la mémoire (facultatif pour foyer, frequence_courses et niveau_cuisine).'],
                    'reason' => ['type' => 'string', 'description' => 'Pourquoi c\'est durable, en quelques mots.'],
                ],
                'required' => ['action', 'category', 'value', 'reason'],
            ],
        ];
    }

    public function supports(ShoppingSession $session): bool
    {
        return true;
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $action = \is_string($args['action'] ?? null) ? $args['action'] : '';
        $category = MemoryCategory::tryFrom(\is_string($args['category'] ?? null) ? $args['category'] : '');
        $value = \is_string($args['value'] ?? null) ? trim($args['value']) : '';
        $oldValue = \is_string($args['old_value'] ?? null) ? trim($args['old_value']) : null;
        $reason = \is_string($args['reason'] ?? null) ? mb_substr(trim($args['reason']), 0, 500) : null;

        if (null === $category) {
            return ToolResult::error('Catégorie invalide.', ['Catégories possibles : '.implode(', ', MemoryCategory::values())]);
        }
        if ('' === $value) {
            return ToolResult::error('"value" est obligatoire.');
        }

        try {
            $change = match ($action) {
                'add' => $this->memory->add($context->user, $category, $value, MemoryOrigin::Live, $context->session, $reason),
                'update' => $this->memory->update(
                    $this->memory->find($context->user, $category, $oldValue) ?? throw $this->notFound($context, $category, $oldValue ?? ''),
                    $value,
                    MemoryOrigin::Live,
                    $context->session,
                    $reason,
                ),
                'delete' => $this->memory->delete(
                    $this->memory->find($context->user, $category, $value) ?? throw $this->notFound($context, $category, $value),
                    MemoryOrigin::Live,
                    $context->session,
                    $reason,
                ),
                default => throw new MemoryException('invalid_action', 'Action invalide : add, update ou delete.'),
            };
            // Visible tout de suite pour les opérations suivantes du même tour (dans la transaction du tour).
            $this->em->flush();
        } catch (MemoryException $e) {
            if ('memory_duplicate' === $e->errorCode && 'add' === $action) {
                return ToolResult::ok(['status' => 'already_known', 'detail' => $e->getMessage()]);
            }

            return ToolResult::error($e->getMessage(), $this->currentValues($context, $category));
        }

        return ToolResult::ok(
            ['status' => 'ok', 'action' => $change->event->getAction()->value, 'category' => $category->value, 'value' => $change->item?->getValue() ?? $change->event->getOldValue()],
            [['type' => 'memory', 'event_id' => $change->event->getId()]],
        );
    }

    private function notFound(ToolContext $context, MemoryCategory $category, string $value): MemoryException
    {
        return new MemoryException('not_found', \sprintf('"%s" n\'est pas dans la mémoire (catégorie %s).', $value, $category->value));
    }

    /** @return list<string> */
    private function currentValues(ToolContext $context, MemoryCategory $category): array
    {
        $values = array_map(static fn ($item): string => $item->getValue(), $this->items->findInCategory($context->user, $category));

        return [\sprintf('Valeurs actuelles de %s : %s', $category->value, [] === $values ? '(aucune)' : '« '.implode(' », « ', $values).' »')];
    }
}
