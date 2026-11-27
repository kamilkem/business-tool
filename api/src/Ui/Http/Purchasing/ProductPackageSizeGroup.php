<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Domain\PackageSize\PackageSizeGroup;

/** The items of a product with one unit and one package size (ADR 0049). */
final readonly class ProductPackageSizeGroup
{
    public function __construct(
        /** The unit's key (lower case, no diacritics, no trailing dot): what `POST /products/{id}/package-sizes` takes back; null: the items have no unit. */
        public ?string $unitKey,
        /** The unit as the seller printed it most often; null: no unit. */
        public ?string $unit,
        /** A decimal string in the product's base unit per one unit of the quantity (of one single package for a bundle); null: the items have none. */
        public ?string $packageSize,
        /** Single packages per unit of the quantity (ADR 0048); null: the items are no bundle. */
        public ?int $bundleCount,
        public int $count,
        /** How many of them a person confirmed. */
        public int $confirmedCount,
    ) {
    }

    public static function fromGroup(PackageSizeGroup $group): self
    {
        return new self($group->unitKey, $group->unit, $group->packageSize, $group->bundleCount, $group->count, $group->confirmedCount);
    }
}
