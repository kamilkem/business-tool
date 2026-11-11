<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetShift;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetShiftHandler implements QueryHandlerInterface
{
    public function __construct(
        private ShiftRepositoryInterface $shifts,
    ) {
    }

    public function __invoke(GetShiftQuery $query): ?ShiftView
    {
        if (!Uuid::isValid($query->shiftId) || !Uuid::isValid($query->userId)) {
            return null;
        }

        $shift = $this->shifts->findById(Uuid::fromString($query->shiftId));
        if (null === $shift || !$shift->getUserId()->equals(Uuid::fromString($query->userId))) {
            return null;
        }

        return ShiftView::fromShift($shift);
    }
}
