<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemoryCategory;
use App\Entity\User;
use App\Entity\UserMemory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMemory>
 */
class UserMemoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMemory::class);
    }

    /** @return list<UserMemory> par catégorie puis ordre d'ajout */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['id' => 'ASC']);
    }

    /** @return list<UserMemory> */
    public function findInCategory(User $user, MemoryCategory $category): array
    {
        return $this->findBy(['user' => $user, 'category' => $category], ['id' => 'ASC']);
    }

    public function findOneByNormalized(User $user, MemoryCategory $category, string $normalizedValue): ?UserMemory
    {
        return $this->findOneBy(['user' => $user, 'category' => $category, 'normalizedValue' => $normalizedValue]);
    }

    public function findOneOwnedBy(int $id, User $user): ?UserMemory
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }
}
