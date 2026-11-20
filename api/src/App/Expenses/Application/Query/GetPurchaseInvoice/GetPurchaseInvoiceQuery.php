<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetPurchaseInvoice;

use App\Shared\Application\Query\QueryInterface;

/**
 * One purchase invoice of the current organization (TenantFilter) with its VAT lines, its category's
 * name and whether it has an original document, for an owner or manager. Returns null when the
 * invoice does not exist there or the actor is not an active member; throws when the actor is an
 * employee (ADR 0021).
 */
final readonly class GetPurchaseInvoiceQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $invoiceId,
    ) {
    }
}
