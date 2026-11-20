<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Shared\Domain\Time\BusinessDate;
use Symfony\Component\Uid\Uuid;

/**
 * One person's hourly rates, oldest first: each is in effect from its date until the day before the
 * next one (ADR 0027 §3). The rate writes use it to find a rate on the same date and the dates a change
 * affects.
 */
final readonly class HourlyRateHistory
{
    /** @param list<HourlyRate> $rates oldest first */
    private function __construct(
        public Uuid $userId,
        private array $rates,
    ) {
    }

    /**
     * @param list<HourlyRate> $rates the person's rates, in any order
     *                                (HourlyRateRepositoryInterface::findByUser())
     */
    public static function of(Uuid $userId, array $rates): self
    {
        foreach ($rates as $rate) {
            if (!$rate->getUserId()->equals($userId)) {
                throw new \LogicException(\sprintf('Hourly rate "%s" is not one of user "%s".', $rate->getId()->toRfc4122(), $userId->toRfc4122()));
            }
        }
        usort($rates, static fn (HourlyRate $a, HourlyRate $b): int => $a->getEffectiveFrom()->toString() <=> $b->getEffectiveFrom()->toString());

        return new self($userId, $rates);
    }

    /** @return list<HourlyRate> oldest first */
    public function all(): array
    {
        return $this->rates;
    }

    /** The rate that takes effect on exactly this date, if any. */
    public function startingOn(BusinessDate $date): ?HourlyRate
    {
        foreach ($this->rates as $rate) {
            if ($rate->getEffectiveFrom()->equals($date)) {
                return $rate;
            }
        }

        return null;
    }

    /** The rate in effect on the date: the one with the latest date not after it. */
    public function inEffectOn(BusinessDate $date): ?HourlyRate
    {
        $inEffect = null;
        foreach ($this->rates as $rate) {
            if ($rate->getEffectiveFrom()->isAfter($date)) {
                break;
            }
            $inEffect = $rate;
        }

        return $inEffect;
    }

    /**
     * The last date that a rate taking effect on $from is in effect: the day before the next rate,
     * or null when no rate follows (open-ended).
     *
     * @param ?HourlyRate $except a rate to leave out, the one being moved
     */
    public function lastDateFrom(BusinessDate $from, ?HourlyRate $except = null): ?BusinessDate
    {
        foreach ($this->rates as $rate) {
            if ((null !== $except && $rate->getId()->equals($except->getId())) || !$rate->getEffectiveFrom()->isAfter($from)) {
                continue;
            }

            return $rate->getEffectiveFrom()->previous();
        }

        return null;
    }
}
