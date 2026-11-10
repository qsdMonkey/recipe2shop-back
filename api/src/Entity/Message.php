<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'message')]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShoppingSession $session;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Turn $turn;

    #[ORM\Column(length: 20, enumType: MessageRole::class)]
    private MessageRole $role;

    /** Texte brut du message : c'est lui qui est renvoyé à Gemini dans l'historique. */
    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /**
     * Blocs affichés par l'app, dans l'ordre (contrat API). Les cartes y sont stockées sous forme de référence
     * et hydratées à la lecture, pour toujours refléter l'état actuel.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $blocks;

    /**
     * Trace des appels d'outils du tour (nom, arguments, résultat). Audit uniquement, jamais envoyé à l'app.
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $toolCalls;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array<string, mixed>>      $blocks
     * @param list<array<string, mixed>>|null $toolCalls
     */
    public function __construct(ShoppingSession $session, ?Turn $turn, MessageRole $role, string $content, array $blocks, ?array $toolCalls = null)
    {
        $this->session = $session;
        $this->turn = $turn;
        $this->role = $role;
        $this->content = $content;
        $this->blocks = $blocks;
        $this->toolCalls = $toolCalls;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ShoppingSession
    {
        return $this->session;
    }

    public function getTurn(): ?Turn
    {
        return $this->turn;
    }

    public function getRole(): MessageRole
    {
        return $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /** @return list<array<string, mixed>> */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /** @return list<array<string, mixed>>|null */
    public function getToolCalls(): ?array
    {
        return $this->toolCalls;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
