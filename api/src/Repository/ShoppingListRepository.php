<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShoppingList;
use App\Entity\ShoppingListType;
use App\Entity\ShoppingSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShoppingList>
 */
class ShoppingListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShoppingList::class);
    }

    public function findOneOwnedBy(int $id, User $user): ?ShoppingList
    {
        return $this->createQueryBuilder('l')
            ->join('l.session', 's')
            ->where('l.id = :id')->andWhere('s.user = :user')
            ->setParameter('id', $id)->setParameter('user', $user)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<ShoppingList> */
    public function findForSession(ShoppingSession $session): array
    {
        return $this->findBy(['session' => $session], ['id' => 'ASC']);
    }

    public function findMain(ShoppingSession $session): ?ShoppingList
    {
        return $this->findOneBy(['session' => $session, 'type' => ShoppingListType::Main]);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, ShoppingList>
     */
    public function findByIds(array $ids): array
    {
        $result = [];
        foreach ([] === $ids ? [] : $this->findBy(['id' => $ids]) as $list) {
            $result[(int) $list->getId()] = $list;
        }

        return $result;
    }
}
