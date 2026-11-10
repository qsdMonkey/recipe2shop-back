<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MemoryEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Historique des changements de mémoire : alimente les puces « 📝 Retenu » et le bouton Annuler. */
#[ORM\Entity(repositoryClass: MemoryEventRepository::class)]
#[ORM\Table(name: 'memory_event')]
class MemoryEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ShoppingSession $session;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Message $message = null;

    /** Élément concerné (null s'il a été supprimé depuis). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserMemory $memory;

    #[ORM\Column(length: 10, enumType: MemoryAction::class)]
    private MemoryAction $action;

    #[ORM\Column(length: 30, enumType: MemoryCategory::class)]
    private MemoryCategory $category;

    #[ORM\Column(length: UserMemory::MAX_LENGTH, nullable: true)]
    private ?string $oldValue;

    #[ORM\Column(length: UserMemory::MAX_LENGTH, nullable: true)]
    private ?string $newValue;

    #[ORM\Column(length: 10, enumType: MemoryOrigin::class)]
    private MemoryOrigin $origin;

    /** Justification donnée par l'IA (audit). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $undoneAt = null;

    public function __construct(
        User $user,
        ?ShoppingSession $session,
        ?UserMemory $memory,
        MemoryAction $action,
        MemoryCategory $category,
        ?string $oldValue,
        ?string $newValue,
        MemoryOrigin $origin,
        ?string $reason = null,
    ) {
        $this->user = $user;
        $this->session = $session;
        $this->memory = $memory;
        $this->action = $action;
        $this->category = $category;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->origin = $origin;
        $this->reason = $reason;
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

    public function getMemory(): ?UserMemory
    {
        return $this->memory;
    }

    public function setMemory(?UserMemory $memory): void
    {
        $this->memory = $memory;
    }

    public function getAction(): MemoryAction
    {
        return $this->action;
    }

    public function getCategory(): MemoryCategory
    {
        return $this->category;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function getOrigin(): MemoryOrigin
    {
        return $this->origin;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function isUndone(): bool
    {
        return null !== $this->undoneAt;
    }

    public function markUndone(): void
    {
        $this->undoneAt = new \DateTimeImmutable();
    }
}
