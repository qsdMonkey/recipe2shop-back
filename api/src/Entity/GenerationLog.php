<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace de chaque appel à Gemini : tokens, coût, durée, modèle, version du prompt.
 * Conservée (anonymisée) à la suppression du compte ou de la session : elle ne contient aucun texte de conversation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'generation_log')]
#[ORM\Index(name: 'idx_generation_log_created', columns: ['created_at'])]
class GenerationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ShoppingSession $session;

    /** conversation | greeting | memory_review… */
    #[ORM\Column(length: 30)]
    private string $purpose;

    #[ORM\Column(length: 100)]
    private string $model;

    #[ORM\Column(length: 50)]
    private string $promptVersion;

    #[ORM\Column]
    private int $inputTokens;

    #[ORM\Column]
    private int $outputTokens;

    #[ORM\Column]
    private int $thinkingTokens;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6)]
    private string $costUsd;

    #[ORM\Column]
    private int $durationMs;

    #[ORM\Column]
    private bool $success;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ?User $user,
        ?ShoppingSession $session,
        string $purpose,
        string $model,
        string $promptVersion,
        int $inputTokens,
        int $outputTokens,
        int $thinkingTokens,
        string $costUsd,
        int $durationMs,
        bool $success,
        ?string $error,
    ) {
        $this->user = $user;
        $this->session = $session;
        $this->purpose = $purpose;
        $this->model = $model;
        $this->promptVersion = $promptVersion;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->thinkingTokens = $thinkingTokens;
        $this->costUsd = $costUsd;
        $this->durationMs = $durationMs;
        $this->success = $success;
        $this->error = $error;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function getPromptVersion(): string
    {
        return $this->promptVersion;
    }

    public function getCostUsd(): string
    {
        return $this->costUsd;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }
}
