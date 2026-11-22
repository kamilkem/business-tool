<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListCashMovements;

use App\Shared\Application\Query\QueryInterface;

/**
 * The cash movements of the current organization (TenantFilter) at a moment within business dates
 * `from` to `to`, inclusive, in the organization's time zone (ADR 0017), newest first; optionally
 * only those on one storage. For owners and managers (ADR 0033 §6; 403 for employees). Returns null
 * when the actor is not an active member. Closes join the history in #185.
 */
final readonly class ListCashMovementsQuery implements QueryInterface
{
    public const int MAX_DAYS = 92;

    public function __construct(
        public string $organizationId,
        public string $actorId,
        /** Y-m-d */
        public string $from,
        /** Y-m-d */
        public string $to,
        public ?string $storageId = null,
    ) {
    }
}
