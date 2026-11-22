<?php

declare(strict_types=1);

namespace App\Income\Application\EventHandler;

use App\CashFlow\Domain\Event\DayCloseRecorded;
use App\Income\Application\Command\BookDayCloseRevenue\BookDayCloseRevenueCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A recorded end-of-day close is the day's revenue entry of the register it chose (ADR 0033 §9),
 * booked as the member who closed. It runs synchronously in the request that recorded the close,
 * with its organization selected.
 */
final readonly class BookRevenueOnDayCloseRecorded implements EventHandlerInterface
{
    public function __construct(private CommandBusInterface $commandBus)
    {
    }

    public function __invoke(DayCloseRecorded $event): void
    {
        $this->commandBus->handle(new BookDayCloseRevenueCommand(
            $event->organizationId,
            $event->actorId,
            $event->dayCloseId,
            Uuid::v7()->toRfc4122(),
            $event->registerId,
            $event->date,
            $event->currency,
            $event->cashIncome,
            $event->nonCashAmounts,
        ));
    }
}
