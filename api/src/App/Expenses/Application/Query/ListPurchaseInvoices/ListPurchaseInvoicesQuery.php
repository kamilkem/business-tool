<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListPurchaseInvoices;

use App\Shared\Application\Query\QueryInterface;

/**
 * The purchase invoices of the current organization (TenantFilter) issued from $from to $to (both
 * inclusive, YYYY-MM-DD; ADR 0017), newest first, with their totals per currency. Narrowed to one
 * category ($categoryId) or to invoices without one ($uncategorized), not both, and to sellers whose
 * name or tax ID contains $seller.
 *
 * Owners and managers only (ADR 0021). Returns null when the actor is not an active member.
 */
final readonly class ListPurchaseInvoicesQuery implements QueryInterface
{
    /** The longest period one request may cover, in days: a (leap) year. */
    public const int MAX_DAYS = 366;

    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $from,
        public string $to,
        public ?string $categoryId = null,
        public bool $uncategorized = false,
        public ?string $seller = null,
    ) {
    }
}
