<?php

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Domain\Event\DayCloseChanged;
use App\CashFlow\Domain\Model\DayClose;
use App\Shared\Domain\Event\EventPublisherInterface;

/** Tells Income that what it books from a saved close changed (ADR 0033 §7, §9): DayCloseChanged, with primitives. */
final readonly class DayCloseBooking
{
    public function __construct(
        private EventPublisherInterface $events,
    ) {
    }

    public function changed(DayClose $close, string $organizationId, string $actorId): void
    {
        $this->events->publish(new DayCloseChanged(
            $organizationId,
            $close->getId()->toRfc4122(),
            $close->getRegisterId()->toRfc4122(),
            $close->getBusinessDate()->toString(),
            $close->getCurrency(),
            $close->getCashIncome(),
            $close->nonCashAmounts(),
            $actorId,
        ));
    }
}
