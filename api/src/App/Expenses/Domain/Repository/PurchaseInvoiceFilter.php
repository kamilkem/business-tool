<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Repository;

use App\Shared\Domain\Time\BusinessPeriod;

/**
 * Which purchase invoices a list shows: those issued in a period of business dates (ADR 0017) whose
 * seller matches a search text.
 */
final readonly class PurchaseInvoiceFilter
{
    /** The search text, trimmed and lower-cased; null when there is none. */
    public ?string $seller;

    /**
     * @param ?string $seller a part of the seller's name (case-insensitive) or tax ID (spaces and dashes ignored)
     */
    public function __construct(
        public BusinessPeriod $period,
        ?string $seller = null,
    ) {
        $seller = null === $seller ? '' : mb_strtolower(trim($seller));
        $this->seller = '' === $seller ? null : $seller;
    }

    /** The search text without spaces and dashes, as tax IDs are compared; null when nothing is left. */
    public function sellerTaxId(): ?string
    {
        $taxId = null === $this->seller ? '' : str_replace([' ', '-'], '', $this->seller);

        return '' === $taxId ? null : $taxId;
    }
}
