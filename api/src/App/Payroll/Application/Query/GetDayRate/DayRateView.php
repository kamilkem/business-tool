<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetDayRate;

use App\Payroll\Domain\Model\DayRate;

final readonly class DayRateView
{
    public function __construct(
        public string $id,
        public string $userId,
        /** YYYY-MM-DD */
        public string $date,
        /** Gross minor units per hour. */
        public int $amount,
        public string $currency,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function of(DayRate $dayRate): self
    {
        return new self(
            $dayRate->getId()->toRfc4122(),
            $dayRate->getUserId()->toRfc4122(),
            $dayRate->getDate()->toString(),
            $dayRate->getAmount(),
            $dayRate->getCurrency(),
            $dayRate->getCreatedAt(),
            $dayRate->getUpdatedAt(),
        );
    }
}
