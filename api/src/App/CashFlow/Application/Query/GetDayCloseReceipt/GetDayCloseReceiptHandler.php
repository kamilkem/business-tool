<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayCloseReceipt;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\DayCloseViews;
use App\CashFlow\Application\Query\GetDayClose\DayCloseView;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetDayCloseReceiptHandler implements QueryHandlerInterface
{
    public function __construct(
        private DayCloseRepositoryInterface $closes,
        private DayCloseViews $views,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @return DayCloseView|null without expected openings */
    public function __invoke(GetDayCloseReceiptQuery $query): ?DayCloseView
    {
        $actor = CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }

        $close = Uuid::isValid($query->dayCloseId) ? $this->closes->findById(Uuid::fromString($query->dayCloseId)) : null;
        if (null === $close || (!$actor->role->canManageOrganization() && !$close->getClosedBy()->equals($actor->id))) {
            return null;
        }

        return $this->views->receipt($close, $query->organizationId, $query->actorId);
    }
}
