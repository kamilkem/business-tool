<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

use App\Purchasing\Domain\Model\AliasKind;

/** What linked an item to its product when matching did (ADR 0045 §1): the owner's alias of a kind, or the model. */
enum MatchSource: string
{
    case Gtin = 'gtin';
    case SellerCode = 'seller_code';
    case SellerName = 'seller_name';
    case Name = 'name';
    /** The model answered with a product of the catalog. */
    case Model = 'model';
    /** The model proposed a new product whose name a product of the catalog has (ADR 0044 §3). */
    case ProposedName = 'proposed_name';

    public static function ofAlias(AliasKind $kind): self
    {
        return match ($kind) {
            AliasKind::Gtin => self::Gtin,
            AliasKind::SellerCode => self::SellerCode,
            AliasKind::SellerName => self::SellerName,
            AliasKind::Name => self::Name,
        };
    }
}
