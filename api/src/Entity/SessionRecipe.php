<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SessionRecipeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Une recette proposée dans une session, et la décision de l'utilisateur. */
#[ORM\Entity(repositoryClass: SessionRecipeRepository::class)]
#[ORM\Table(name: 'session_recipe')]
#[ORM\UniqueConstraint(name: 'uniq_session_recipe', columns: ['session_id', 'recipe_id'])]
class SessionRecipe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShoppingSession $session;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Recipe $recipe;

    #[ORM\Column(length: 20, enumType: SessionRecipeStatus::class)]
    private SessionRecipeStatus $status = SessionRecipeStatus::Proposed;

    #[ORM\Column]
    private int $servings;

    #[ORM\Column(nullable: true)]
    private ?int $suggestedDay;

    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private bool $cooked = false;

    /** @var list<string> avertissements affichés sur la carte (ex. ustensile absent du profil) */
    #[ORM\Column(type: Types::JSON)]
    private array $warnings;

    /** Commentaire du refus (« moins épicé »), gardé dans le contexte de l'IA. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private \DateTimeImmutable $proposedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** @param list<string> $warnings */
    public function __construct(ShoppingSession $session, Recipe $recipe, ?int $suggestedDay, int $position, array $warnings)
    {
        $this->session = $session;
        $this->recipe = $recipe;
        $this->servings = $recipe->getServings();
        $this->suggestedDay = $suggestedDay;
        $this->position = $position;
        $this->warnings = $warnings;
        $this->proposedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ShoppingSession
    {
        return $this->session;
    }

    public function getRecipe(): Recipe
    {
        return $this->recipe;
    }

    public function getStatus(): SessionRecipeStatus
    {
        return $this->status;
    }

    public function isProposed(): bool
    {
        return SessionRecipeStatus::Proposed === $this->status;
    }

    public function decide(SessionRecipeStatus $status, ?string $comment = null): void
    {
        $this->status = $status;
        $this->comment = null === $comment || '' === trim($comment) ? null : mb_substr(trim($comment), 0, 500);
        $this->decidedAt = new \DateTimeImmutable();
        $this->session->bumpListInputsVersion();
    }

    public function getServings(): int
    {
        return $this->servings;
    }

    public function getSuggestedDay(): ?int
    {
        return $this->suggestedDay;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function markCooked(): void
    {
        $this->cooked = true;
    }

    public function isCooked(): bool
    {
        return $this->cooked;
    }

    /** @return list<string> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }
}
