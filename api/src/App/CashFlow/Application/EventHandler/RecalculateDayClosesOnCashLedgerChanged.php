<?php

declare(strict_types=1);

namespace App\CashFlow\Application\EventHandler;

use App\CashFlow\Application\Command\RecalculateDayClose\RecalculateDayCloseCommand;
use App\CashFlow\Domain\Event\CashLedgerChanged;
use App\CashFlow\Domain\Model\DayClose;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A change of the cash ledger changes the expected opening of the first close after it that counted
 * each storage it touches (ADR 0033 §7): one RecalculateDayCloseCommand per such close, one aggregate
 * per command. Later closes are anchored by that close's own count. It runs synchronously in the
 * request that changed the ledger, with its organization selected.
 */
final readonly class RecalculateDayClosesOnCashLedgerChanged implements EventHandlerInterface
{
    public function __construct(
        private DayCloseRepositoryInterface $closes,
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(CashLedgerChanged $event): void
    {
        $position = new LedgerPosition(new \DateTimeImmutable($event->occurredAt), Uuid::fromString($event->recordId));

        /** @var array<string, DayClose> $next */
        $next = [];
        foreach ($event->storageIds as $storageId) {
            $close = $this->closes->findFirstCountingAfter(Uuid::fromString($storageId), $position);
            if (null !== $close) {
                $next[$close->getId()->toRfc4122()] = $close;
            }
        }

        foreach (array_keys($next) as $dayCloseId) {
            $this->commandBus->handle(new RecalculateDayCloseCommand($event->organizationId, $event->actorId, (string) $dayCloseId));
        }
    }
}
