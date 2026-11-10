<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserMemoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Un élément de la mémoire longue de l'utilisateur (« airfryer » dans « ustensile »). */
#[ORM\Entity(repositoryClass: UserMemoryRepository::class)]
#[ORM\Table(name: 'user_memory')]
#[ORM\UniqueConstraint(name: 'uniq_user_memory_value', columns: ['user_id', 'category', 'normalized_value'])]
class UserMemory
{
    public const MAX_LENGTH = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 30, enumType: MemoryCategory::class)]
    private MemoryCategory $category;

    #[ORM\Column(length: self::MAX_LENGTH)]
    private string $value;

    /** Valeur normalisée (minuscules, sans accents ni espaces en trop) : détection des doublons. */
    #[ORM\Column(length: self::MAX_LENGTH)]
    private string $normalizedValue;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ShoppingSession $sourceSession;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, MemoryCategory $category, string $value, string $normalizedValue, ?ShoppingSession $sourceSession)
    {
        $this->user = $user;
        $this->category = $category;
        $this->value = $value;
        $this->normalizedValue = $normalizedValue;
        $this->sourceSession = $sourceSession;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCategory(): MemoryCategory
    {
        return $this->category;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getNormalizedValue(): string
    {
        return $this->normalizedValue;
    }

    public function changeValue(string $value, string $normalizedValue): void
    {
        $this->value = $value;
        $this->normalizedValue = $normalizedValue;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
