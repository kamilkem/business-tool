<?php

declare(strict_types=1);

namespace App\Income\Application\Command\RemoveDayCloseRevenue;

use App\Income\Application\RevenueEntryHistory;
use App\Income\Domain\Exception\RevenueMonthClosedException;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use Psr\Clock\ClockInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The entry booked from the close goes with it, its VAT breakdown and note included, even when the
 * close took over an entry entered by hand: its payment lines were the close's. Nothing booked
 * (the booking failed, or it was removed already) is nothing to remove. An entry in a closed month
 * stays (ADR 0034 §2): Cash flow refuses such a delete up front, so this is the last guard.
 */
final readonly class RemoveDayCloseRevenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private RevenueEntryRepositoryInterface $entries,
        private RevenueMonthRepositoryInterface $months,
        private RevenueEntryHistory $history,
        private ClockInterface $clock,
    ) {
    }

    /** @throws RevenueMonthClosedException when the entry's business date is in a closed month */
    public function __invoke(RemoveDayCloseRevenueCommand $command): void
    {
        $dayCloseId = Uuid::fromString($command->dayCloseId);
        $entry = $this->entries->findByDayCloseId($dayCloseId);
        if (null === $entry) {
            return;
        }
        $this->months->locks()->assertOpen($entry->getBusinessDate());

        $this->entries->remove($entry);
        $this->history->deleted($entry, $dayCloseId, Uuid::fromString($command->actorId), $this->clock->now());
    }
}
