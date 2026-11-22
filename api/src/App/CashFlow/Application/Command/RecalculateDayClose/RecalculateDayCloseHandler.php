<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\RecalculateDayClose;

use App\CashFlow\Application\DayCloseBooking;
use App\CashFlow\Application\ExpectedOpenings;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Recomputes a close's expected openings and cash income from the ledger (ADR 0033 §7). Its counts
 * do not change, so no close after it changes: the recalculation stops here. When the cash income
 * changed, it publishes DayCloseChanged, and Income books it again. A close deleted in the meantime
 * is nothing to recompute.
 */
final readonly class RecalculateDayCloseHandler implements CommandHandlerInterface
{
    public function __construct(
        private DayCloseRepositoryInterface $closes,
        private ExpectedOpenings $openings,
        private DayCloseBooking $booking,
    ) {
    }

    public function __invoke(RecalculateDayCloseCommand $command): void
    {
        $close = Uuid::isValid($command->dayCloseId) ? $this->closes->findById(Uuid::fromString($command->dayCloseId)) : null;
        if (null === $close) {
            return;
        }

        if (!$close->recalculate($this->openings->at($close->position(), $close->countedStorages(), $close->getCurrency()))) {
            return;
        }
        $this->closes->save($close);
        $this->booking->changed($close, $command->organizationId, $command->actorId);
    }
}
