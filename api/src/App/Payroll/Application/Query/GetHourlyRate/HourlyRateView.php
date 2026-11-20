<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetHourlyRate;

use App\Payroll\Domain\Model\HourlyRate;
use App\Payroll\Domain\Model\HourlyRateHistory;

final readonly class HourlyRateView
{
    public function __construct(
        public string $id,
        public string $userId,
        /** YYYY-MM-DD: the first date the rate is in effect. */
        public string $effectiveFrom,
        /** YYYY-MM-DD, inclusive: the day before the member's next rate; null while no rate follows. */
        public ?string $effectiveTo,
        /** Gross minor units per hour. */
        public int $amount,
        public string $currency,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    /** @param HourlyRateHistory $history the member's rates, this one included */
    public static function of(HourlyRate $rate, HourlyRateHistory $history): self
    {
        return new self(
            $rate->getId()->toRfc4122(),
            $rate->getUserId()->toRfc4122(),
            $rate->getEffectiveFrom()->toString(),
            $history->lastDateFrom($rate->getEffectiveFrom())?->toString(),
            $rate->getAmount(),
            $rate->getCurrency(),
            $rate->getCreatedAt(),
            $rate->getUpdatedAt(),
        );
    }
}
