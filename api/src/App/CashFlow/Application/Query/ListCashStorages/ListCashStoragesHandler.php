<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListCashStorages;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\Query\GetCashStorage\CashStorageView;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListCashStoragesHandler implements QueryHandlerInterface
{
    public function __construct(
        private CashStorageRepositoryInterface $storages,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @return list<CashStorageView>|null active storages first, each group by name */
    public function __invoke(ListCashStoragesQuery $query): ?array
    {
        if (null === CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $used = array_flip($this->storages->usedIds());

        return array_map(
            static fn (CashStorage $storage): CashStorageView => CashStorageView::fromStorage($storage, isset($used[$storage->getId()->toRfc4122()])),
            $this->storages->findAll($query->includeDeactivated),
        );
    }
}
