<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * An owner or manager deleted an end-of-day close, and that is saved (ADR 0033 §7, §9). Income
 * removes the revenue entry booked from it; its cash counts in the next close of each storage.
 */
final readonly class DayCloseDeleted implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $dayCloseId,
        public string $registerId,
        /** Y-m-d: the business date of the close. */
        public string $date,
        /** The owner or manager who deleted it. */
        public string $actorId,
    ) {
    }
}
