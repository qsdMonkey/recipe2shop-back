<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShoppingSession;
use App\Entity\Turn;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Turn>
 */
class TurnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Turn::class);
    }

    public function findByClientId(ShoppingSession $session, string $clientId): ?Turn
    {
        return $this->findOneBy(['session' => $session, 'clientId' => strtolower($clientId)]);
    }
}
