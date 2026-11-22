<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\Shared\Domain\Time\BusinessDate;
use Symfony\Component\Uid\Uuid;

/** An existing close of a date, with the name of the member who made it: what a second close of it is told (409). */
final readonly class ClosedDay
{
    public function __construct(
        public Uuid $dayCloseId,
        public BusinessDate $date,
        public Uuid $closedBy,
        public string $closedByFirstName,
        public string $closedByLastName,
        public \DateTimeImmutable $closedAt,
    ) {
    }
}
