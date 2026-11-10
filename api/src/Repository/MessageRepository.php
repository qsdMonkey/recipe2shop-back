<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use App\Entity\MessageRole;
use App\Entity\ShoppingSession;
use App\Entity\Turn;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /** @return list<Message> du plus ancien au plus récent */
    public function findForSession(ShoppingSession $session, ?int $afterId = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.session = :session')
            ->setParameter('session', $session)
            ->orderBy('m.id', \SortDirection::Ascending);
        if (null !== $afterId) {
            $qb->andWhere('m.id > :afterId')->setParameter('afterId', $afterId);
        }

        return $qb->getQuery()->getResult();
    }

    /** Un bloc de ce type a-t-il déjà été affiché dans la session ? */
    public function sessionHasBlockType(ShoppingSession $session, string $type): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT 1 FROM message WHERE session_id = ? AND JSON_CONTAINS(blocks, JSON_OBJECT('type', ?)) LIMIT 1",
            [$session->getId(), $type],
        );
    }

    /** @return list<Message> */
    public function findForTurn(Turn $turn): array
    {
        return $this->findBy(['turn' => $turn], ['id' => 'ASC']);
    }

    /**
     * Les N derniers messages user/assistant (historique envoyé à Gemini), du plus ancien au plus récent.
     *
     * @return list<Message>
     */
    public function findRecentConversation(ShoppingSession $session, int $limit): array
    {
        $messages = $this->createQueryBuilder('m')
            ->where('m.session = :session')
            ->andWhere('m.role IN (:roles)')
            ->setParameter('session', $session)
            ->setParameter('roles', [MessageRole::User->value, MessageRole::Assistant->value])
            ->orderBy('m.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($messages);
    }
}
