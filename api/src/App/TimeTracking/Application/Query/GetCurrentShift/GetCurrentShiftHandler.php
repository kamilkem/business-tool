<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetCurrentShift;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetCurrentShiftHandler implements QueryHandlerInterface
{
    public function __construct(
        private ShiftRepositoryInterface $shifts,
    ) {
    }

    public function __invoke(GetCurrentShiftQuery $query): ?ShiftView
    {
        if (!Uuid::isValid($query->userId)) {
            return null;
        }

        $shift = $this->shifts->findOpenByUser(Uuid::fromString($query->userId));

        return null === $shift ? null : ShiftView::fromShift($shift);
    }
}
