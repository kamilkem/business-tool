<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Repository;

use App\Expenses\Domain\Exception\InvalidExpenseFilterException;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

/**
 * Which purchase invoices a list shows: those issued in a period of business dates (ADR 0017), of one
 * category or uncategorized only, and whose seller matches a search text.
 */
final readonly class PurchaseInvoiceFilter
{
    /** The search text, trimmed and lower-cased; null when there is none. */
    public ?string $seller;

    /**
     * @param ?string $seller a part of the seller's name (case-insensitive) or tax ID (spaces and dashes ignored)
     *
     * @throws InvalidExpenseFilterException when both a category and uncategorized are asked for
     */
    public function __construct(
        public BusinessPeriod $period,
        public ?Uuid $categoryId = null,
        public bool $uncategorized = false,
        ?string $seller = null,
    ) {
        if (null !== $categoryId && $uncategorized) {
            throw InvalidExpenseFilterException::categoryAndUncategorized();
        }
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
