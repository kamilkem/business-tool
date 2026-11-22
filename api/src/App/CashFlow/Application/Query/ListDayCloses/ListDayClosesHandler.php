<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListDayCloses;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Application\DayCloseViews;
use App\CashFlow\Application\Query\GetDayClose\DayCloseView;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DayClosePeriodTooLongException;
use App\CashFlow\Domain\Model\CashFlowAccess;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;

final readonly class ListDayClosesHandler implements QueryHandlerInterface
{
    public function __construct(
        private DayCloseRepositoryInterface $closes,
        private DayCloseViews $views,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<DayCloseView>|null latest date first
     *
     * @throws TimeException                    when $from or $to is not a date, or $to is before $from
     * @throws DayClosePeriodTooLongException   when the period is longer than ListDayClosesQuery::MAX_DAYS
     * @throws CashFlowAccessForbiddenException when the actor is an employee
     */
    public function __invoke(ListDayClosesQuery $query): ?array
    {
        $period = BusinessPeriod::fromStrings($query->from, $query->to);
        if ($period->dayCount() > ListDayClosesQuery::MAX_DAYS) {
            throw DayClosePeriodTooLongException::of($period, ListDayClosesQuery::MAX_DAYS);
        }

        $actor = CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }
        CashFlowAccess::assertCanReadDayCloses($actor->id, $actor->role);

        return $this->views->details($this->closes->findInPeriod($period), $query->organizationId, $query->actorId);
    }
}
