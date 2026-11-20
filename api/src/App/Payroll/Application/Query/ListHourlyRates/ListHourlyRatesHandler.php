<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ListHourlyRates;

use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Application\Query\GetHourlyRate\HourlyRateView;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Model\HourlyRate;
use App\Payroll\Domain\Model\HourlyRateHistory;
use App\Payroll\Domain\Repository\HourlyRateRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ListHourlyRatesHandler implements QueryHandlerInterface
{
    public function __construct(
        private HourlyRateRepositoryInterface $rates,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<HourlyRateView>|null by member, newest first
     *
     * @throws PayrollAccessForbiddenException when an employee asks for someone else's rates
     * @throws MemberNotFoundException         when $userId is not a member of the organization
     */
    public function __invoke(ListHourlyRatesQuery $query): ?array
    {
        $actor = PayrollActor::member($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }
        $userId = $actor->visibleMember($this->queryBus, $query->userId);

        $rates = $this->rates->findAll($userId);
        $byUser = [];
        foreach ($rates as $rate) {
            $byUser[$rate->getUserId()->toRfc4122()][] = $rate;
        }
        $histories = [];
        foreach ($byUser as $id => $userRates) {
            $histories[$id] = HourlyRateHistory::of(Uuid::fromString($id), $userRates);
        }

        return array_map(
            static fn (HourlyRate $rate): HourlyRateView => HourlyRateView::of($rate, $histories[$rate->getUserId()->toRfc4122()]),
            $rates,
        );
    }
}
