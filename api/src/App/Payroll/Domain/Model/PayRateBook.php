<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Shared\Domain\Time\BusinessDate;
use Symfony\Component\Uid\Uuid;

/**
 * Which rate pays a person's shifts of a business date (ADR 0027 §3): their day rate for that date
 * if there is one, else the hourly rate in effect on it. The currency is not checked here: a rate in
 * another currency than the run's counts as no rate, which the run decides (#62).
 */
final readonly class PayRateBook
{
    /**
     * @param array<string, HourlyRateHistory> $histories by user id
     * @param array<string, DayRate>           $dayRates  by user id and date ("<userId> <Y-m-d>")
     */
    private function __construct(
        private array $histories,
        private array $dayRates,
    ) {
    }

    /**
     * @param list<HourlyRate> $hourlyRates every hourly rate of the people asked about (not only the
     *                                      ones in a period: an older one may still be in effect)
     * @param list<DayRate>    $dayRates    their day rates on the dates asked about
     */
    public static function of(array $hourlyRates, array $dayRates): self
    {
        $byUser = [];
        foreach ($hourlyRates as $rate) {
            $byUser[$rate->getUserId()->toRfc4122()][] = $rate;
        }
        $histories = [];
        foreach ($byUser as $userId => $rates) {
            $histories[$userId] = HourlyRateHistory::of(Uuid::fromString($userId), $rates);
        }

        $days = [];
        foreach ($dayRates as $dayRate) {
            $days[self::key($dayRate->getUserId(), $dayRate->getDate())] = $dayRate;
        }

        return new self($histories, $days);
    }

    /** The rate of the person's shifts that started on the date, or null when they have none. */
    public function on(Uuid $userId, BusinessDate $date): HourlyRate|DayRate|null
    {
        return $this->dayRates[self::key($userId, $date)]
            ?? ($this->histories[$userId->toRfc4122()] ?? null)?->inEffectOn($date);
    }

    /** @return list<string> the ids of the people with an hourly or a day rate */
    public function userIds(): array
    {
        $userIds = array_keys($this->histories);
        foreach ($this->dayRates as $dayRate) {
            $userIds[] = $dayRate->getUserId()->toRfc4122();
        }
        $userIds = array_values(array_unique(array_map(strval(...), $userIds)));
        sort($userIds);

        return $userIds;
    }

    private static function key(Uuid $userId, BusinessDate $date): string
    {
        return $userId->toRfc4122().' '.$date->toString();
    }
}
