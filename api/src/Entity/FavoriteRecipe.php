<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FavoriteRecipeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Recette favorite d'un utilisateur. Garde l'accès à la recette même après la suppression de la session
 * où elle a été proposée (règle des 3) : les recettes ne sont jamais supprimées.
 */
#[ORM\Entity(repositoryClass: FavoriteRecipeRepository::class)]
#[ORM\Table(name: 'favorite_recipe')]
#[ORM\UniqueConstraint(name: 'uniq_favorite_recipe', columns: ['user_id', 'recipe_id'])]
class FavoriteRecipe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Recipe $recipe;

    /**
     * Nombre de fois où l'utilisateur a accepté la recette. Compteur stocké (et non calculé depuis session_recipe)
     * pour survivre à la suppression des vieilles sessions : initialisé à la mise en favori, incrémenté à chaque acceptation.
     */
    #[ORM\Column]
    private int $timesAccepted;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Recipe $recipe, int $timesAccepted)
    {
        $this->user = $user;
        $this->recipe = $recipe;
        $this->timesAccepted = $timesAccepted;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRecipe(): Recipe
    {
        return $this->recipe;
    }

    public function getTimesAccepted(): int
    {
        return $this->timesAccepted;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
