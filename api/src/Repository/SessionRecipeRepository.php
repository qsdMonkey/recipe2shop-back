<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SessionRecipe;
use App\Entity\SessionRecipeStatus;
use App\Entity\ShoppingSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SessionRecipe>
 */
class SessionRecipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SessionRecipe::class);
    }

    /** @return list<SessionRecipe> dans l'ordre de proposition */
    public function findForSession(ShoppingSession $session): array
    {
        return $this->createQueryBuilder('sr')
            ->addSelect('r')
            ->join('sr.recipe', 'r')
            ->where('sr.session = :session')
            ->setParameter('session', $session)
            ->orderBy('sr.position', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function findOneInSession(ShoppingSession $session, int $recipeId): ?SessionRecipe
    {
        return $this->findOneBy(['session' => $session, 'recipe' => $recipeId]);
    }

    public function findPending(ShoppingSession $session): ?SessionRecipe
    {
        return $this->findOneBy(['session' => $session, 'status' => SessionRecipeStatus::Proposed]);
    }

    public function mealsCovered(ShoppingSession $session): int
    {
        return (int) $this->createQueryBuilder('sr')
            ->select('COALESCE(SUM(r.mealsCovered), 0)')
            ->join('sr.recipe', 'r')
            ->where('sr.session = :session')
            ->andWhere('sr.status = :accepted')
            ->setParameter('session', $session)
            ->setParameter('accepted', SessionRecipeStatus::Accepted)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function nextPosition(ShoppingSession $session): int
    {
        return 1 + (int) $this->createQueryBuilder('sr')
            ->select('COALESCE(MAX(sr.position), 0)')
            ->where('sr.session = :session')
            ->setParameter('session', $session)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** La recette est-elle visible par cet utilisateur (créée par lui ou présente dans une de ses sessions) ? */
    public function isVisibleTo(int $recipeId, User $user): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM recipe r LEFT JOIN session_recipe sr ON sr.recipe_id = r.id LEFT JOIN shopping_session s ON s.id = sr.session_id
             WHERE r.id = ? AND (r.created_by_id = ? OR s.user_id = ?) LIMIT 1',
            [$recipeId, $user->getId(), $user->getId()],
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, SessionRecipe>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $result = [];
        foreach ($this->createQueryBuilder('sr')->addSelect('r')->join('sr.recipe', 'r')->where('sr.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $sr) {
            $result[(int) $sr->getId()] = $sr;
        }

        return $result;
    }
}
