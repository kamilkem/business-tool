<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListCashMovements;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\CashMovementViews;
use App\CashFlow\Application\Query\GetCashMovement\CashMovementView;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\MovementPeriodTooLongException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Model\CashFlowAccess;
use App\CashFlow\Domain\Repository\CashMovementRepositoryInterface;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use Symfony\Component\Uid\Uuid;

final readonly class ListCashMovementsHandler implements QueryHandlerInterface
{
    public function __construct(
        private CashMovementRepositoryInterface $movements,
        private CashStorageRepositoryInterface $storages,
        private CashMovementViews $views,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<CashMovementView>|null newest first
     *
     * @throws TimeException                    when $from or $to is not a date, or $to is before $from
     * @throws MovementPeriodTooLongException   when the period is longer than ListCashMovementsQuery::MAX_DAYS
     * @throws CashFlowAccessForbiddenException when the actor is an employee
     * @throws StorageNotFoundException         when $storageId is not one of the organization's storages
     */
    public function __invoke(ListCashMovementsQuery $query): ?array
    {
        $period = BusinessPeriod::fromStrings($query->from, $query->to);
        if ($period->dayCount() > ListCashMovementsQuery::MAX_DAYS) {
            throw MovementPeriodTooLongException::of($period, ListCashMovementsQuery::MAX_DAYS);
        }

        $actor = CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }
        CashFlowAccess::assertCanReadLedger($actor->id, $actor->role);

        $storageId = null;
        if (null !== $query->storageId) {
            $storage = Uuid::isValid($query->storageId) ? $this->storages->findById(Uuid::fromString($query->storageId)) : null;
            $storageId = ($storage ?? throw StorageNotFoundException::withId($query->storageId))->getId();
        }

        $movements = $this->movements->findOccurredIn(
            $period->toUtcRange(new \DateTimeZone($actor->organization->timezone)),
            $storageId,
        );

        return $this->views->of($movements, $query->organizationId, $query->actorId);
    }
}
