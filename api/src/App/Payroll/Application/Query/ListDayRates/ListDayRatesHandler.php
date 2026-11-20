<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ListDayRates;

use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Application\Query\GetDayRate\DayRateView;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Repository\DayRateRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListDayRatesHandler implements QueryHandlerInterface
{
    public function __construct(
        private DayRateRepositoryInterface $dayRates,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<DayRateView>|null by member, newest first
     *
     * @throws PayrollAccessForbiddenException when an employee asks for someone else's day rates
     * @throws MemberNotFoundException         when $userId is not a member of the organization
     */
    public function __invoke(ListDayRatesQuery $query): ?array
    {
        $actor = PayrollActor::member($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }

        return array_map(
            DayRateView::of(...),
            $this->dayRates->findAll($actor->visibleMember($this->queryBus, $query->userId)),
        );
    }
}
