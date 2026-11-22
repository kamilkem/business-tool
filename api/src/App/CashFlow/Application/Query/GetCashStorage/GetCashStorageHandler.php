<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetCashStorage;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Model\CashFlowAccess;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetCashStorageHandler implements QueryHandlerInterface
{
    public function __construct(
        private CashStorageRepositoryInterface $storages,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws CashFlowAccessForbiddenException when the query is to manage and the actor is an employee */
    public function __invoke(GetCashStorageQuery $query): ?CashStorageView
    {
        $actor = CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }
        if ($query->toManage) {
            CashFlowAccess::assertCanManage($actor->id, $actor->role);
        }

        $storage = Uuid::isValid($query->storageId) ? $this->storages->findById(Uuid::fromString($query->storageId)) : null;

        return null === $storage ? null : CashStorageView::fromStorage($storage, $this->storages->isUsed($storage->getId()));
    }
}
