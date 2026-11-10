<?php

declare(strict_types=1);

namespace App\ShoppingList;

use App\Entity\ShoppingList;
use App\Entity\ShoppingListItem;
use App\Presenter\ShoppingListPresenter;

/** Export texte pour la feuille de partage du téléphone (articles cochés exclus). */
final class ShoppingListExporter
{
    private const AISLE_LABELS = [
        'fruits_legumes' => 'Fruits et légumes',
        'boulangerie' => 'Boulangerie',
        'boucherie_poissonnerie' => 'Boucherie et poissonnerie',
        'cremerie' => 'Crèmerie',
        'epicerie' => 'Épicerie',
        'epicerie_sucree' => 'Épicerie sucrée',
        'surgeles' => 'Surgelés',
        'boissons' => 'Boissons',
        'hygiene_entretien' => 'Hygiène et entretien',
        'autre' => 'Autres',
    ];

    /** @var array<string, array{0: string, 1: string}> singulier, pluriel */
    private const UNIT_LABELS = [
        'g' => ['g', 'g'], 'kg' => ['kg', 'kg'], 'ml' => ['ml', 'ml'], 'cl' => ['cl', 'cl'], 'l' => ['l', 'l'],
        'cas' => ['c. à s.', 'c. à s.'], 'cac' => ['c. à c.', 'c. à c.'],
        'pincee' => ['pincée', 'pincées'], 'gousse' => ['gousse', 'gousses'], 'botte' => ['botte', 'bottes'],
        'tranche' => ['tranche', 'tranches'], 'boite' => ['boîte', 'boîtes'], 'sachet' => ['sachet', 'sachets'],
    ];

    public function export(ShoppingList $list): string
    {
        $sections = $basics = [];
        $count = 0;
        foreach ($list->getItems() as $item) {
            if ($item->isChecked() || $item->isCoveredByInventory()) {
                continue;
            }
            if ($item->isBasic()) {
                $basics[] = $item->getLabel();
                continue;
            }
            $sections[$item->getCategory()][] = $item;
            ++$count;
        }

        $collator = new \Collator('fr_FR');
        $lines = [\sprintf('%s (%d article%s)', $list->getName(), $count, $count > 1 ? 's' : '')];
        foreach (ShoppingListPresenter::AISLES as $aisle) {
            if (!isset($sections[$aisle])) {
                continue;
            }
            usort($sections[$aisle], static fn (ShoppingListItem $a, ShoppingListItem $b): int => $collator->compare($a->getLabel(), $b->getLabel()));
            $lines[] = '';
            $lines[] = self::AISLE_LABELS[$aisle];
            foreach ($sections[$aisle] as $item) {
                $lines[] = '- '.$item->getLabel().self::quantity($item).($item->isOptional() ? ' (facultatif)' : '');
            }
        }
        if ([] !== $basics) {
            $collator->sort($basics);
            $lines[] = '';
            $lines[] = 'À vérifier dans tes placards : '.implode(', ', array_unique($basics));
        }

        return implode("\n", $lines)."\n";
    }

    public static function quantity(ShoppingListItem $item): string
    {
        $quantity = $item->getQuantity();
        if (null === $quantity) {
            return '';
        }
        $number = rtrim(rtrim(number_format($quantity, 2, ',', ''), '0'), ',');
        $unit = $item->getUnit();
        if (null === $unit || 'piece' === $unit) {
            return ' : '.$number;
        }
        [$singular, $plural] = self::UNIT_LABELS[$unit] ?? [$unit, $unit];

        return ' : '.$number.' '.($quantity > 1 ? $plural : $singular);
    }
}
