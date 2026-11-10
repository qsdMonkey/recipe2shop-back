<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\SessionType;
use App\Entity\User;
use App\Repository\ShoppingSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Règle des 3 : au plus 3 conversations de courses (1 active + 2 archivées). Les plus anciennes sont supprimées,
 * mais seulement APRÈS une relecture mémoire réussie. Seule la conversation est supprimée (messages, tours,
 * inventaire, recettes de la session) : les recettes restent en base, la mémoire et ses événements aussi.
 */
final class SessionRetention
{
    public const ARCHIVED_KEPT = 2;

    public function __construct(
        private readonly ShoppingSessionRepository $sessions,
        private readonly EntityManagerInterface $em,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'monolog.logger.recipe2shop')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apply(User $user): void
    {
        $archived = $this->sessions->findArchived($user, SessionType::Shopping, 100);
        foreach (\array_slice($archived, self::ARCHIVED_KEPT) as $session) {
            if (!$session->isMemoryReviewed()) {
                continue; // supprimée plus tard, après sa relecture
            }
            $id = $session->getId();
            $this->em->detach($session);
            // Suppression SQL directe : les tables liées suivent par ON DELETE CASCADE / SET NULL.
            $this->em->getConnection()->delete('shopping_session', ['id' => $id]);
            $this->logger->info('session.deleted_rule_of_3', ['session_id' => $id]);
        }
    }
}
