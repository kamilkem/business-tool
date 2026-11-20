<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetPayRate;

use App\Payroll\Domain\Model\PayRateBook;
use App\Payroll\Domain\Repository\DayRateRepositoryInterface;
use App\Payroll\Domain\Repository\HourlyRateRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use Symfony\Component\Uid\Uuid;

final readonly class GetPayRateHandler implements QueryHandlerInterface
{
    public function __construct(
        private HourlyRateRepositoryInterface $rates,
        private DayRateRepositoryInterface $dayRates,
    ) {
    }

    /** @throws TimeException when $date is not a date */
    public function __invoke(GetPayRateQuery $query): ?PayRateView
    {
        $date = BusinessDate::fromString($query->date);
        if (!Uuid::isValid($query->userId)) {
            return null;
        }
        $userId = Uuid::fromString($query->userId);

        $rate = PayRateBook::of(
            $this->rates->findByUser($userId),
            $this->dayRates->findIn(BusinessPeriod::day($date), $userId),
        )->on($userId, $date);

        return null === $rate ? null : PayRateView::of($rate, $date);
    }
}
