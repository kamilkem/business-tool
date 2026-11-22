<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetCashMovement;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\CashMovementViews;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Model\CashFlowAccess;
use App\CashFlow\Domain\Repository\CashMovementRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetCashMovementHandler implements QueryHandlerInterface
{
    public function __construct(
        private CashMovementRepositoryInterface $movements,
        private CashMovementViews $views,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws CashFlowAccessForbiddenException when the actor is an employee */
    public function __invoke(GetCashMovementQuery $query): ?CashMovementView
    {
        $actor = CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }
        CashFlowAccess::assertCanReadLedger($actor->id, $actor->role);

        $movement = Uuid::isValid($query->movementId) ? $this->movements->findById(Uuid::fromString($query->movementId)) : null;

        return null === $movement ? null : ($this->views->of([$movement], $query->organizationId, $query->actorId)[0] ?? null);
    }
}
