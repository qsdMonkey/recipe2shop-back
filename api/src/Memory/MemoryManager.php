<?php

declare(strict_types=1);

namespace App\Memory;

use App\Entity\MemoryAction;
use App\Entity\MemoryCategory;
use App\Entity\MemoryEvent;
use App\Entity\MemoryOrigin;
use App\Entity\ShoppingSession;
use App\Entity\User;
use App\Entity\UserMemory;
use App\Repository\UserMemoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Toutes les écritures dans la mémoire longue passent ici (IA en direct, relecture, page utilisateur) :
 * contrôles par le code (catégorie, doublons, valeur unique, « aucune ») et historique dans memory_event.
 * Ne fait que persist() : le flush est à la charge de l'appelant (transaction du tour ou contrôleur).
 */
final class MemoryManager
{
    private const NONE_VALUES = ['aucun', 'aucune', 'aucuns', 'aucunes', 'rien', 'non', 'none', 'pas de', 'sans'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserMemoryRepository $items,
    ) {
    }

    public static function normalize(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), ['œ' => 'oe', 'æ' => 'ae']);
        $value = (string) \Normalizer::normalize($value, \Normalizer::FORM_D);
        $value = (string) preg_replace('/\p{Mn}+/u', '', $value);      // accents
        $value = (string) preg_replace('/[\s\x{2019}\']+/u', ' ', $value);
        $value = trim($value, " \t\n\r\0\x0B.,;:!?");

        return mb_substr($value, 0, UserMemory::MAX_LENGTH);
    }

    public static function isNoneValue(string $value): bool
    {
        return \in_array(self::normalize($value), self::NONE_VALUES, true);
    }

    /** @throws MemoryException */
    public function add(User $user, MemoryCategory $category, string $value, MemoryOrigin $origin, ?ShoppingSession $session = null, ?string $reason = null): MemoryChange
    {
        $value = $this->clean($value);
        $normalized = self::normalize($value);
        $existing = $this->items->findInCategory($user, $category);

        foreach ($existing as $item) {
            if ($item->getNormalizedValue() === $normalized) {
                throw new MemoryException('memory_duplicate', \sprintf('"%s" is already known in category "%s".', $item->getValue(), $category->value));
            }
        }

        if ($category->isSingleValued() && [] !== $existing) {
            return $this->update($existing[0], $value, $origin, $session, $reason);
        }

        if ($category->acceptsNone()) {
            if (self::isNoneValue($value) && [] !== $existing) {
                throw new MemoryException('memory_duplicate', \sprintf('Category "%s" already has values: remove them first.', $category->value));
            }
            if (!self::isNoneValue($value)) {
                // Une vraie valeur remplace « aucune » (sans puce dans le chat).
                foreach ($existing as $item) {
                    if (self::isNoneValue($item->getValue())) {
                        $this->delete($item, $origin, $session, 'Remplacé par une vraie valeur.');
                    }
                }
            }
        }

        $item = new UserMemory($user, $category, $value, $normalized, $session);
        $this->em->persist($item);
        $event = new MemoryEvent($user, $session, $item, MemoryAction::Add, $category, null, $value, $origin, $reason);
        $this->em->persist($event);

        return new MemoryChange($item, $event, true);
    }

    /** @throws MemoryException */
    public function update(UserMemory $item, string $newValue, MemoryOrigin $origin, ?ShoppingSession $session = null, ?string $reason = null): MemoryChange
    {
        $newValue = $this->clean($newValue);
        $normalized = self::normalize($newValue);
        $this->assertNoOtherWithValue($item, $normalized);

        $oldValue = $item->getValue();
        $item->changeValue($newValue, $normalized);
        $event = new MemoryEvent($item->getUser(), $session, $item, MemoryAction::Update, $item->getCategory(), $oldValue, $newValue, $origin, $reason);
        $this->em->persist($event);

        return new MemoryChange($item, $event, false);
    }

    public function delete(UserMemory $item, MemoryOrigin $origin, ?ShoppingSession $session = null, ?string $reason = null): MemoryChange
    {
        $event = new MemoryEvent($item->getUser(), $session, null, MemoryAction::Delete, $item->getCategory(), $item->getValue(), null, $origin, $reason);
        $this->em->persist($event);
        $this->removeItem($item);

        return new MemoryChange(null, $event, false);
    }

    /**
     * Bouton « Annuler » d'une puce mémoire.
     *
     * @throws MemoryException
     */
    public function undo(MemoryEvent $event): void
    {
        if ($event->isUndone()) {
            throw new MemoryException('already_undone', 'This change has already been undone.');
        }

        $user = $event->getUser();
        $category = $event->getCategory();
        $item = $event->getMemory();

        switch ($event->getAction()) {
            case MemoryAction::Add:
                if (null !== $item) {
                    $this->removeItem($item);
                }
                break;

            case MemoryAction::Update:
                if (null === $item || null === $event->getOldValue()) {
                    throw new MemoryException('undo_conflict', 'The value has been removed since.');
                }
                $normalized = self::normalize($event->getOldValue());
                $this->assertNoOtherWithValue($item, $normalized, 'undo_conflict');
                $item->changeValue($event->getOldValue(), $normalized);
                break;

            case MemoryAction::Delete:
                $value = (string) $event->getOldValue();
                $normalized = self::normalize($value);
                if (null !== $this->items->findOneByNormalized($user, $category, $normalized)) {
                    break; // déjà présente : rien à faire
                }
                if ($category->isSingleValued() && [] !== $this->items->findInCategory($user, $category)) {
                    throw new MemoryException('undo_conflict', 'Another value has been set since.');
                }
                $restored = new UserMemory($user, $category, $value, $normalized, null);
                $this->em->persist($restored);
                $event->setMemory($restored);
                break;
        }

        $event->markUndone();
    }

    /**
     * Retrouve un élément par sa valeur (comparaison normalisée). Pour une catégorie à valeur unique, la valeur
     * peut être omise.
     */
    public function find(User $user, MemoryCategory $category, ?string $value): ?UserMemory
    {
        if (null === $value || '' === trim($value)) {
            return $category->isSingleValued() ? ($this->items->findInCategory($user, $category)[0] ?? null) : null;
        }

        return $this->items->findOneByNormalized($user, $category, self::normalize($value));
    }

    /**
     * Supprime un élément. Les événements déjà chargés qui le référencent sont détachés de lui (en base, la FK passe
     * à NULL via ON DELETE SET NULL) : sinon le flush suivant de la même requête échoue (« new entity found »).
     */
    private function removeItem(UserMemory $item): void
    {
        $uow = $this->em->getUnitOfWork();
        $managed = [...array_values($uow->getIdentityMap()[MemoryEvent::class] ?? []), ...array_values($uow->getScheduledEntityInsertions())];
        foreach ($managed as $event) {
            if ($event instanceof MemoryEvent && $event->getMemory() === $item) {
                $event->setMemory(null);
            }
        }
        $this->em->remove($item);
    }

    /** @throws MemoryException */
    private function clean(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ('' === $value || '' === self::normalize($value)) {
            throw new MemoryException('validation_failed', 'The value must not be empty.');
        }
        if (mb_strlen($value) > UserMemory::MAX_LENGTH) {
            throw new MemoryException('validation_failed', \sprintf('The value must not exceed %d characters.', UserMemory::MAX_LENGTH));
        }

        return $value;
    }

    /** @throws MemoryException */
    private function assertNoOtherWithValue(UserMemory $item, string $normalized, string $errorCode = 'memory_duplicate'): void
    {
        $other = $this->items->findOneByNormalized($item->getUser(), $item->getCategory(), $normalized);
        if (null !== $other && $other !== $item) {
            throw new MemoryException($errorCode, \sprintf('"%s" is already known in category "%s".', $other->getValue(), $item->getCategory()->value));
        }
    }
}
