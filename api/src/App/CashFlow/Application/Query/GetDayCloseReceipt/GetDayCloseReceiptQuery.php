<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayCloseReceipt;

use App\Shared\Application\Query\QueryInterface;

/**
 * What the member who closed a day is shown afterwards (ADR 0033 §6): what they entered, the totals
 * per storage and the cash income, with no balance or expected opening. For the member who closed
 * it, or an owner or manager. Returns null when the actor is not an active member, the close does
 * not exist, or an employee asks about someone else's close.
 */
final readonly class GetDayCloseReceiptQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $dayCloseId,
    ) {
    }
}
