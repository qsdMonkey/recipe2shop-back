<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FavoriteRecipe;
use App\Entity\SessionRecipeStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FavoriteRecipe>
 */
class FavoriteRecipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FavoriteRecipe::class);
    }

    /** @return list<FavoriteRecipe> du plus récent au plus ancien */
    public function findForUser(User $user, ?int $limit = null): array
    {
        return $this->createQueryBuilder('f')
            ->addSelect('r')
            ->join('f.recipe', 'r')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.createdAt', \SortDirection::Descending)
            ->addOrderBy('f.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneFor(User $user, int $recipeId): ?FavoriteRecipe
    {
        return $this->findOneBy(['user' => $user, 'recipe' => $recipeId]);
    }

    /** @return array<int, true> ids des recettes favorites de l'utilisateur (pour le champ "favorite" des cartes) */
    public function recipeIdsFor(User $user): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn('SELECT recipe_id FROM favorite_recipe WHERE user_id = ?', [$user->getId()]);

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /** Acceptations de la recette par l'utilisateur dans ses sessions encore en base (initialisation du compteur). */
    public function countAcceptances(User $user, int $recipeId): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM session_recipe sr JOIN shopping_session s ON s.id = sr.session_id WHERE s.user_id = ? AND sr.recipe_id = ? AND sr.status = ?',
            [$user->getId(), $recipeId, SessionRecipeStatus::Accepted->value],
        );
    }

    /** Une acceptation de plus (sans effet si la recette n'est pas en favori). */
    public function incrementAcceptances(User $user, int $recipeId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE favorite_recipe SET times_accepted = times_accepted + 1 WHERE user_id = ? AND recipe_id = ?',
            [$user->getId(), $recipeId],
        );
    }
}
