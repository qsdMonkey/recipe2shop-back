<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ShoppingListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShoppingListRepository::class)]
#[ORM\Table(name: 'shopping_list')]
class ShoppingList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShoppingSession $session;

    #[ORM\Column(length: 10, enumType: ShoppingListType::class)]
    private ShoppingListType $type;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Dernier calcul depuis les recettes (liste main). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $generatedAt = null;

    /** Version des données de la session (ShoppingSession::listInputsVersion) au dernier calcul. */
    #[ORM\Column(options: ['default' => 0])]
    private int $generatedFromVersion = 0;

    /** @var list<int> ingrédients dont l'utilisateur a supprimé l'article : exclus aux régénérations */
    #[ORM\Column(type: Types::JSON)]
    private array $excludedIngredientIds = [];

    /** @var Collection<int, ShoppingListItem> */
    #[ORM\OneToMany(targetEntity: ShoppingListItem::class, mappedBy: 'list', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    public function __construct(ShoppingSession $session, ShoppingListType $type, string $name)
    {
        $this->session = $session;
        $this->type = $type;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ShoppingSession
    {
        return $this->session;
    }

    public function getType(): ShoppingListType
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function markGenerated(): void
    {
        $this->generatedAt = new \DateTimeImmutable();
        $this->generatedFromVersion = $this->session->getListInputsVersion();
    }

    public function getGeneratedFromVersion(): int
    {
        return $this->generatedFromVersion;
    }

    /** @return list<int> */
    public function getExcludedIngredientIds(): array
    {
        return $this->excludedIngredientIds;
    }

    public function exclude(int $ingredientId): void
    {
        if (!\in_array($ingredientId, $this->excludedIngredientIds, true)) {
            $this->excludedIngredientIds[] = $ingredientId;
        }
    }

    /** @return Collection<int, ShoppingListItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(ShoppingListItem $item): void
    {
        $this->items->add($item);
    }

    public function removeItem(ShoppingListItem $item): void
    {
        $this->items->removeElement($item);
    }
}
