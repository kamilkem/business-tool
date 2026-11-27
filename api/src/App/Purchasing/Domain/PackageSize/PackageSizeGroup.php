<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\PackageSize;

use App\Expenses\Domain\Model\DecimalString;
use App\Purchasing\Domain\Matching\ItemReader;
use App\Purchasing\Domain\Model\PurchasedItem;

/**
 * The items of a product with one unit and one package (the size and the bundle count, ADR 0048), which is what a person chooses to change (ADR 0049).
 * A unit is its key (ItemReader::unitKeyOf(): `szt.` and `SZT` are one unit), shown as the seller printed it most often.
 */
final readonly class PackageSizeGroup
{
    public function __construct(
        /** ItemReader::unitKeyOf(); null: no unit. */
        public ?string $unitKey,
        /** The spelling of the unit most items have; null: no unit. */
        public ?string $unit,
        /** A decimal string, trimmed, of one single package for a bundle; null: the items have none. */
        public ?string $packageSize,
        /** Single packages per unit of the quantity; null: no bundle (and always null with no package size). */
        public ?int $bundleCount,
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
        /** @var array<string, array{unitKey: ?string, size: ?string, bundle: ?int, count: int, confirmed: int, spellings: array<string, int>}> $merged */
        $merged = [];
        foreach ($counts as $row) {
            $unitKey = ItemReader::unitKeyOf($row->unit);
            $size = DecimalString::trimmed($row->packageSize);
            $bundle = null === $size ? null : $row->bundleCount;
            $index = ($unitKey ?? '').'|'.($size ?? '').'|'.($bundle ?? '');
            $merged[$index] ??= ['unitKey' => $unitKey, 'size' => $size, 'bundle' => $bundle, 'count' => 0, 'confirmed' => 0, 'spellings' => []];
            $merged[$index]['count'] += $row->count;
            $merged[$index]['confirmed'] += $row->confirmed ? $row->count : 0;
            if (null !== $unitKey && null !== $row->unit) {
                $merged[$index]['spellings'][$row->unit] = ($merged[$index]['spellings'][$row->unit] ?? 0) + $row->count;
            }
        }

        $groups = array_map(static function (array $group): self {
            $spellings = $group['spellings'];
            uksort($spellings, static fn (string $a, string $b): int => [$spellings[$b], $a] <=> [$spellings[$a], $b]);

            return new self($group['unitKey'], [] === $spellings ? null : array_key_first($spellings), $group['size'], $group['bundle'], $group['count'], $group['confirmed']);
        }, array_values($merged));

        usort($groups, static fn (self $a, self $b): int => [null === $a->unitKey, $a->unitKey ?? '', null !== $a->packageSize, (float) $a->packageSize, $a->bundleCount ?? 0]
            <=> [null === $b->unitKey, $b->unitKey ?? '', null !== $b->packageSize, (float) $b->packageSize, $b->bundleCount ?? 0]);

        return $groups;
    }

    /** Whether the item is one of the group's: the same unit key, package size and bundle count. */
    public function holds(PurchasedItem $item): bool
    {
        return ItemReader::unitKeyOf($item->getUnit()) === $this->unitKey && $item->getPackageSize() === $this->packageSize && $item->getBundleCount() === $this->bundleCount;
    }
}
