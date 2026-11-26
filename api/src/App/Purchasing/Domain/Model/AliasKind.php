<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

/**
 * What a ProductAlias remembers an item by (ADR 0042 §2), strongest first: a GTIN stands for one pack
 * of one product everywhere, a seller's code for one pack from that seller, a seller's exact name the
 * same when it has no code, and the normalized name for the product across sellers.
 */
enum AliasKind: string
{
    case Gtin = 'gtin';
    case SellerCode = 'seller_code';
    case SellerName = 'seller_name';
    case Name = 'name';

    /** Whether the key names one pack, so the alias remembers the item's package size. */
    public function carriesPackageSize(): bool
    {
        return self::Name !== $this;
    }
}
