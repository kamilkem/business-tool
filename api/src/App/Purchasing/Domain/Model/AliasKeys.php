<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

use App\Expenses\Domain\Model\SellerKey;
use App\Purchasing\Domain\Matching\ItemReader;

/**
 * The keys an item is remembered and found by (ADR 0042 §2): one per AliasKind it has, strongest
 * first. A seller is its tax ID, or its name when it has none (SellerKey).
 */
final class AliasKeys
{
    private const int MIN_GTIN_LENGTH = 8;

    private function __construct()
    {
    }

    /** @return array<string, string> key by AliasKind value, in order of strength; kinds the item has no key for are left out */
    public static function of(PurchasedItem $item): array
    {
        $seller = SellerKey::ofTaxId($item->getSellerTaxId()) ?? 'name:'.SellerKey::ofName($item->getSellerName());
        $keys = [];

        $gtin = preg_replace('/\D+/', '', $item->getGtin() ?? '') ?? '';
        if (\strlen($gtin) >= self::MIN_GTIN_LENGTH) {
            $keys[AliasKind::Gtin->value] = $gtin;
        }
        $code = strtoupper(trim($item->getSellerCode() ?? ''));
        if ('' !== $code) {
            $keys[AliasKind::SellerCode->value] = $seller.'|'.$code;
        }
        $full = ItemReader::fullKey($item->getName());
        if ('' !== $full) {
            $keys[AliasKind::SellerName->value] = $seller.'|'.$full;
        }
        $name = ItemReader::nameKey($item->getName());
        if ('' !== $name) {
            $keys[AliasKind::Name->value] = $name;
        }

        return $keys;
    }
}
