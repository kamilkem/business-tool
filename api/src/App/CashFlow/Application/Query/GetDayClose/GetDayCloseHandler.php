<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayClose;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\DayCloseViews;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Model\CashFlowAccess;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetDayCloseHandler implements QueryHandlerInterface
{
    public function __construct(
        private DayCloseRepositoryInterface $closes,
        private DayCloseViews $views,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws CashFlowAccessForbiddenException when the actor is an employee */
    public function __invoke(GetDayCloseQuery $query): ?DayCloseView
    {
        $actor = CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }
        CashFlowAccess::assertCanReadDayCloses($actor->id, $actor->role);

        $close = Uuid::isValid($query->dayCloseId) ? $this->closes->findById(Uuid::fromString($query->dayCloseId)) : null;

        return null === $close ? null : $this->views->details([$close], $query->organizationId, $query->actorId)[0];
    }
}
