<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ListPayRates;

use App\Payroll\Application\Query\GetPayRate\PayRateView;
use App\Payroll\Domain\Model\PayRateBook;
use App\Payroll\Domain\Repository\DayRateRepositoryInterface;
use App\Payroll\Domain\Repository\HourlyRateRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use Symfony\Component\Uid\Uuid;

final readonly class ListPayRatesHandler implements QueryHandlerInterface
{
    public function __construct(
        private HourlyRateRepositoryInterface $rates,
        private DayRateRepositoryInterface $dayRates,
    ) {
    }

    /**
     * @return list<PayRateView> by date, then member id
     *
     * @throws TimeException when $from or $to is not a date, or $to is before $from
     */
    public function __invoke(ListPayRatesQuery $query): array
    {
        $period = BusinessPeriod::fromStrings($query->from, $query->to);
        if ($period->dayCount() > ListPayRatesQuery::MAX_DAYS) {
            throw new \LogicException(\sprintf('ListPayRatesQuery covers at most %d days, %d asked.', ListPayRatesQuery::MAX_DAYS, $period->dayCount()));
        }
        if (null !== $query->userId && !Uuid::isValid($query->userId)) {
            return [];
        }
        $userId = null === $query->userId ? null : Uuid::fromString($query->userId);

        $book = PayRateBook::of($this->rates->findAll($userId), $this->dayRates->findIn($period, $userId));
        $userIds = $book->userIds();

        $views = [];
        for ($date = $period->first; !$date->isAfter($period->last); $date = $date->next()) {
            foreach ($userIds as $id) {
                $rate = $book->on(Uuid::fromString($id), $date);
                if (null !== $rate) {
                    $views[] = PayRateView::of($rate, $date);
                }
            }
        }

        return $views;
    }
}
