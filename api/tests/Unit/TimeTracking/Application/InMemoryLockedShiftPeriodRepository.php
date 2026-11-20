<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\LockedShiftPeriodMother;
use App\TimeTracking\Domain\Model\LockedShiftPeriod;
use App\TimeTracking\Domain\Repository\LockedShiftPeriodRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final class InMemoryLockedShiftPeriodRepository implements LockedShiftPeriodRepositoryInterface
{
    /** @var array<string, LockedShiftPeriod> */
    private array $byId = [];

    public function findAll(): array
    {
        $periods = array_values($this->byId);
        usort($periods, static fn (LockedShiftPeriod $a, LockedShiftPeriod $b): int => [$a->getPeriod()->first->toString(), $a->getId()->toRfc4122()] <=> [$b->getPeriod()->first->toString(), $b->getId()->toRfc4122()]);

        return $periods;
    }

    public function findByLockedBy(Uuid $lockedBy): ?LockedShiftPeriod
    {
        foreach ($this->byId as $period) {
            if ($period->getLockedBy()->equals($lockedBy)) {
                return $period;
            }
        }

        return null;
    }

    public function findContaining(BusinessDate $date): ?LockedShiftPeriod
    {
        foreach ($this->findAll() as $period) {
            if ($period->contains($date)) {
                return $period;
            }
        }

        return null;
    }

    public function add(LockedShiftPeriod $period): void
    {
        $this->byId[$period->getId()->toRfc4122()] = $period;
    }

    public function remove(LockedShiftPeriod $period): void
    {
        unset($this->byId[$period->getId()->toRfc4122()]);
    }

    /** Locks the calendar month for a new locker; returns the lock. */
    public function lockMonth(int $year, int $month): LockedShiftPeriod
    {
        $period = LockedShiftPeriodMother::month($year, $month);
        $this->add($period);

        return $period;
    }

    /** @return list<LockedShiftPeriod> */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
