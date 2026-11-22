<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayCloseForm;

final readonly class DayCloseFormClosedView
{
    public function __construct(
        public string $dayCloseId,
        public string $closedById,
        public string $closedByFirstName,
        public string $closedByLastName,
        public \DateTimeImmutable $closedAt,
    ) {
    }
}
