<?php

declare(strict_types=1);

namespace App\Memory;

use App\Entity\MemoryCategory;
use App\Entity\User;
use App\Repository\UserMemoryRepository;

/**
 * Nombre de personnes du foyer, lu dans la mémoire (texte libre : « 2 adultes et 2 enfants de 6 et 9 ans »,
 * « couple », « seul »). Un enfant compte pour une portion. null si le foyer est inconnu ou illisible.
 */
final class HouseholdSize
{
    private const NUMBERS = ['un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8];
    private const PEOPLE = 'adultes?|personnes?|pers|enfants?|ados?|adolescents?|bebes?|filles?|fils|garcons?|gamins?|petits?';

    public function __construct(private readonly UserMemoryRepository $memory)
    {
    }

    public function forUser(User $user): ?int
    {
        $items = $this->memory->findInCategory($user, MemoryCategory::Foyer);

        return [] === $items ? null : self::parse($items[0]->getValue());
    }

    public static function parse(string $value): ?int
    {
        $text = MemoryManager::normalize($value);
        $number = '(\d{1,2}|'.implode('|', array_keys(self::NUMBERS)).')';

        $total = 0;
        if (preg_match_all('/\b'.$number.'\s+(?:'.self::PEOPLE.')\b/', $text, $matches)) {
            foreach ($matches[1] as $n) {
                $total += self::NUMBERS[$n] ?? (int) $n;
            }
        }
        if (preg_match('/\bcouple\b/', $text)) {
            $total += 2;
        }
        if (0 === $total && preg_match('/\b(?:on est|nous sommes|famille de|foyer de)\s+'.$number.'\b/', $text, $m)) {
            $total = self::NUMBERS[$m[1]] ?? (int) $m[1];
        }
        if (0 === $total && preg_match('/\b(?:seule?|celibataire|moi uniquement|juste moi)\b/', $text)) {
            $total = 1;
        }

        return $total >= 1 && $total <= 20 ? $total : null;
    }
}
