<?php

declare(strict_types=1);

namespace App\Income\Application\EventHandler;

use App\CashFlow\Domain\Event\DayCloseChanged;
use App\Income\Application\Command\BookDayCloseRevenue\BookDayCloseRevenueCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A corrected or recalculated end-of-day close is booked again (ADR 0033 §7, §9): the upsert by the
 * close's id replaces its payment lines, and a close moved to another register removes the old
 * entry and books the new register's. Booked as the owner or manager who changed the close or the
 * ledger before it. The id is used only when the register and date have no entry yet. It runs
 * synchronously in the request that changed the close, with its organization selected.
 */
final readonly class BookRevenueOnDayCloseChanged implements EventHandlerInterface
{
    public function __construct(private CommandBusInterface $commandBus)
    {
    }

    public function __invoke(DayCloseChanged $event): void
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
