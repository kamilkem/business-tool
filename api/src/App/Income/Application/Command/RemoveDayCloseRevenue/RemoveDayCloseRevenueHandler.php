<?php

declare(strict_types=1);

namespace App\Income\Application\Command\RemoveDayCloseRevenue;

use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The entry booked from the close goes with it, its VAT breakdown and note included, even when the
 * close took over an entry entered by hand: its payment lines were the close's. Nothing booked
 * (the booking failed, or it was removed already) is nothing to remove.
 */
final readonly class RemoveDayCloseRevenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private RevenueEntryRepositoryInterface $entries,
    ) {
    }

    public function __invoke(RemoveDayCloseRevenueCommand $command): void
    {
        $entry = $this->entries->findByDayCloseId(Uuid::fromString($command->dayCloseId));
        if (null !== $entry) {
            $this->entries->remove($entry);
        }
    }
}
