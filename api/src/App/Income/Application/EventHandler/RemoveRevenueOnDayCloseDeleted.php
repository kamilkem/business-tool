<?php

declare(strict_types=1);

namespace App\Income\Application\EventHandler;

use App\CashFlow\Domain\Event\DayCloseDeleted;
use App\Income\Application\Command\RemoveDayCloseRevenue\RemoveDayCloseRevenueCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;

/**
 * A deleted end-of-day close takes the revenue entry booked from it along (ADR 0033 §7, §9). It runs
 * synchronously in the request that deleted the close, with its organization selected.
 */
final readonly class RemoveRevenueOnDayCloseDeleted implements EventHandlerInterface
{
    public function __construct(private CommandBusInterface $commandBus)
    {
    }

    public function __invoke(DayCloseDeleted $event): void
    {
        $this->commandBus->handle(new RemoveDayCloseRevenueCommand($event->organizationId, $event->actorId, $event->dayCloseId));
    }
}
