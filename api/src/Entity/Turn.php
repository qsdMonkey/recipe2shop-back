<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TurnRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un tour de conversation (message utilisateur ou action → réponse de l'IA).
 * Sert à l'idempotence (client_id) et à savoir si un tour est en cours.
 */
#[ORM\Entity(repositoryClass: TurnRepository::class)]
#[ORM\Table(name: 'conversation_turn')]
#[ORM\UniqueConstraint(name: 'uniq_turn_session_client', columns: ['session_id', 'client_id'])]
class Turn
{
    public const KIND_GREETING = 'greeting';
    public const KIND_MESSAGE = 'message';
    public const KIND_ACCEPT_RECIPE = 'accept_recipe';
    public const KIND_REJECT_RECIPE = 'reject_recipe';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShoppingSession $session;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $clientId;

    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\Column(length: 20, enumType: TurnStatus::class)]
    private TurnStatus $status = TurnStatus::InProgress;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(ShoppingSession $session, string $kind, ?string $clientId = null)
    {
        $this->session = $session;
        $this->kind = $kind;
        $this->clientId = null === $clientId ? null : strtolower($clientId);
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ShoppingSession
    {
        return $this->session;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getStatus(): TurnStatus
    {
        return $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function complete(): void
    {
        $this->status = TurnStatus::Completed;
        $this->completedAt = new \DateTimeImmutable();
    }
}
