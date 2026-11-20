<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Repository;

use App\Shared\Domain\Time\BusinessDate;
use App\TimeTracking\Domain\Model\LockedShiftPeriod;
use Symfony\Component\Uid\Uuid;

/** Locked shift periods of the current organization only (TenantFilter). */
interface LockedShiftPeriodRepositoryInterface
{
    /**
     * All of the organization's locked periods, oldest first: a few a year (one per approved payroll
     * month), so the shift writes load them whole (ShiftPeriodLocks).
     *
     * @return list<LockedShiftPeriod>
     */
    public function findAll(): array;

    public function findByLockedBy(Uuid $lockedBy): ?LockedShiftPeriod;

    /** A locked period that contains the date, if any (the earliest one, should periods overlap). */
    public function findContaining(BusinessDate $date): ?LockedShiftPeriod;

    public function add(LockedShiftPeriod $period): void;

    public function remove(LockedShiftPeriod $period): void;
}
