<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SessionStatus;
use App\Entity\SessionType;
use App\Entity\ShoppingSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShoppingSession>
 */
class ShoppingSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShoppingSession::class);
    }

    public function findOneOwnedBy(int $id, User $user): ?ShoppingSession
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    public function findActive(User $user, SessionType $type): ?ShoppingSession
    {
        return $this->findOneBy(['user' => $user, 'type' => $type, 'status' => SessionStatus::Active], ['id' => 'DESC']);
    }

    /** @return list<ShoppingSession> */
    public function findArchived(User $user, SessionType $type, int $limit): array
    {
        return $this->findBy(['user' => $user, 'type' => $type, 'status' => SessionStatus::Archived], ['id' => 'DESC'], $limit);
    }
}
