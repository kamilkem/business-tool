<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\PackageSize;

use App\Expenses\Domain\Model\DecimalString;
use App\Purchasing\Domain\Matching\ItemReader;
use App\Purchasing\Domain\Model\PurchasedItem;

/**
 * The items of a product with one unit and one package size, which is what a person chooses to change (ADR 0048).
 * A unit is its key (ItemReader::unitKeyOf(): `szt.` and `SZT` are one unit), shown as the seller printed it most often.
 */
final readonly class PackageSizeGroup
{
    public function __construct(
        /** ItemReader::unitKeyOf(); null: no unit. */
        public ?string $unitKey,
        /** The spelling of the unit most items have; null: no unit. */
        public ?string $unit,
        /** A decimal string, trimmed; null: the items have none. */
        public ?string $packageSize,
        public int $count,
        /** How many of them a person confirmed. */
        public int $confirmedCount,
    ) {
    }

    /**
     * The counts merged into groups by unit key and package size: by unit (no unit last), then the items with
     * no package size first, then by size.
     *
     * @param list<PackageSizeCount> $counts
     *
     * @return list<self>
     */
    public static function of(array $counts): array
    {
        /** @var array<string, array{unitKey: ?string, size: ?string, count: int, confirmed: int, spellings: array<string, int>}> $merged */
        $merged = [];
        foreach ($counts as $row) {
            $unitKey = ItemReader::unitKeyOf($row->unit);
            $size = DecimalString::trimmed($row->packageSize);
            $index = ($unitKey ?? '').'|'.($size ?? '');
            $merged[$index] ??= ['unitKey' => $unitKey, 'size' => $size, 'count' => 0, 'confirmed' => 0, 'spellings' => []];
            $merged[$index]['count'] += $row->count;
            $merged[$index]['confirmed'] += $row->confirmed ? $row->count : 0;
            if (null !== $unitKey && null !== $row->unit) {
                $merged[$index]['spellings'][$row->unit] = ($merged[$index]['spellings'][$row->unit] ?? 0) + $row->count;
            }
        }

        $groups = array_map(static function (array $group): self {
            $spellings = $group['spellings'];
            uksort($spellings, static fn (string $a, string $b): int => [$spellings[$b], $a] <=> [$spellings[$a], $b]);

            return new self($group['unitKey'], [] === $spellings ? null : array_key_first($spellings), $group['size'], $group['count'], $group['confirmed']);
        }, array_values($merged));

        usort($groups, static fn (self $a, self $b): int => [null === $a->unitKey, $a->unitKey ?? '', null !== $a->packageSize, (float) $a->packageSize]
            <=> [null === $b->unitKey, $b->unitKey ?? '', null !== $b->packageSize, (float) $b->packageSize]);

        return $groups;
    }

    /** Whether the item is one of the group's: the same unit key and the same package size. */
    public function holds(PurchasedItem $item): bool
    {
        return ItemReader::unitKeyOf($item->getUnit()) === $this->unitKey && $item->getPackageSize() === $this->packageSize;
    }
}
