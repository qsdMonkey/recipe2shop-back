<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ShoppingSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une session = une conversation avec l'IA (onboarding, ou une grosse course).
 */
#[ORM\Entity(repositoryClass: ShoppingSessionRepository::class)]
#[ORM\Table(name: 'shopping_session')]
#[ORM\Index(name: 'idx_session_user_type_status', columns: ['user_id', 'type', 'status'])]
class ShoppingSession
{
    public const DEFAULT_PERIOD_DAYS = 7;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: SessionType::class)]
    private SessionType $type;

    #[ORM\Column(length: 20, enumType: SessionStatus::class)]
    private SessionStatus $status = SessionStatus::Active;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    private int $periodDays = self::DEFAULT_PERIOD_DAYS;

    /** Résumé des échanges anciens, injecté dans le contexte (mis à jour plus tard, cf. architecture-ia.md). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    /** Nombre total de repas prévus pour la période (outil set_meal_plan). */
    #[ORM\Column(nullable: true)]
    private ?int $mealsPlanned = null;

    /**
     * Détail des repas prévus (outil set_meal_plan) : dîners, déjeuners, gamelles, notes…
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $mealPlan = null;

    /**
     * Version de ce qui sert à calculer la liste main (décisions sur les recettes, inventaire) : incrémentée à chaque
     * changement. Une liste calculée depuis une version plus ancienne est « outdated » (plus fiable qu'une date à la seconde).
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $listInputsVersion = 0;

    /** Relecture mémoire de fin de session faite (condition pour supprimer la session, règle des 3). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $memoryReviewedAt = null;

    /**
     * Verrou « un tour à la fois » : posé/levé en SQL direct par TurnRunner, jamais modifié via l'ORM.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $turnLockedAt = null;

    public function __construct(User $user, SessionType $type, int $periodDays = self::DEFAULT_PERIOD_DAYS)
    {
        $this->user = $user;
        $this->type = $type;
        $this->periodDays = $periodDays;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): SessionType
    {
        return $this->type;
    }

    public function getStatus(): SessionStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return SessionStatus::Active === $this->status;
    }

    public function archive(): void
    {
        if ($this->isActive()) {
            $this->status = SessionStatus::Archived;
            $this->closedAt = new \DateTimeImmutable();
        }
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getPeriodDays(): int
    {
        return $this->periodDays;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function getMealsPlanned(): ?int
    {
        return $this->mealsPlanned;
    }

    /** @return array<string, mixed>|null */
    public function getMealPlan(): ?array
    {
        return $this->mealPlan;
    }

    /** @param array<string, mixed> $details */
    public function setMealPlan(int $mealsPlanned, array $details): void
    {
        $this->mealsPlanned = $mealsPlanned;
        $this->mealPlan = $details;
    }

    public function getListInputsVersion(): int
    {
        return $this->listInputsVersion;
    }

    /** Une recette acceptée / refusée, ou l'inventaire modifié : la liste main est à recalculer. */
    public function bumpListInputsVersion(): void
    {
        ++$this->listInputsVersion;
    }

    public function isMemoryReviewed(): bool
    {
        return null !== $this->memoryReviewedAt;
    }

    public function markMemoryReviewed(): void
    {
        $this->memoryReviewedAt ??= new \DateTimeImmutable();
    }

    public function touch(\DateTimeImmutable $at): void
    {
        $this->lastMessageAt = $at;
    }
}
