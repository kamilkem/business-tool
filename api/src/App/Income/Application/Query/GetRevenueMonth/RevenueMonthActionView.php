<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueMonth;

use App\Income\Domain\Model\RevenueMonthAction;

final readonly class RevenueMonthActionView
{
    public function __construct(
        /** closed | reopened */
        public string $action,
        public string $actorId,
        public \DateTimeImmutable $at,
    ) {
    }

    public static function of(RevenueMonthAction $action): self
    {
        return new self($action->getKind()->value, $action->getActorId()->toRfc4122(), $action->getAt());
    }
}
