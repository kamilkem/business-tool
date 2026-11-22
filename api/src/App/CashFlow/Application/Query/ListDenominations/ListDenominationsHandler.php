<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListDenominations;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\Query\GetDenomination\DenominationView;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListDenominationsHandler implements QueryHandlerInterface
{
    public function __construct(
        private DenominationRepositoryInterface $denominations,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @return list<DenominationView>|null active denominations first, each group by value, lowest first */
    public function __invoke(ListDenominationsQuery $query): ?array
    {
        $actor = CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }

        $used = array_flip($this->denominations->usedIds());

        return array_map(
            static fn (Denomination $denomination): DenominationView => DenominationView::fromDenomination($denomination, isset($used[$denomination->getId()->toRfc4122()])),
            $this->denominations->findByCurrency($actor->organization->currency, $query->includeDeactivated),
        );
    }
}
