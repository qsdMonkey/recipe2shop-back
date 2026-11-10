<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemoryEvent;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemoryEvent>
 */
class MemoryEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemoryEvent::class);
    }

    public function findOneOwnedBy(int $id, User $user): ?MemoryEvent
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, MemoryEvent> indexé par id
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $events = [];
        foreach ($this->findBy(['id' => $ids]) as $event) {
            $events[(int) $event->getId()] = $event;
        }

        return $events;
    }

    /** @param list<int> $ids */
    public function attachToMessage(array $ids, Message $message): void
    {
        if ([] === $ids) {
            return;
        }

        $this->createQueryBuilder('e')
            ->update()
            ->set('e.message', ':message')
            ->where('e.id IN (:ids)')
            ->setParameter('message', $message)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
