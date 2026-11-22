<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDenomination;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetDenominationHandler implements QueryHandlerInterface
{
    public function __construct(
        private DenominationRepositoryInterface $denominations,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(GetDenominationQuery $query): ?DenominationView
    {
        if (null === CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $denomination = Uuid::isValid($query->denominationId) ? $this->denominations->findById(Uuid::fromString($query->denominationId)) : null;

        return null === $denomination ? null : DenominationView::fromDenomination($denomination, $this->denominations->isUsed($denomination->getId()));
    }
}
