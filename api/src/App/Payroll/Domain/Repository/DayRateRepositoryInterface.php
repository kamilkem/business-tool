<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Repository;

use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Model\DayRate;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

/** Day rates of the current organization only (TenantFilter). */
interface DayRateRepositoryInterface
{
    public function findById(Uuid $id): ?DayRate;

    public function findByUserAndDate(Uuid $userId, BusinessDate $date): ?DayRate;

    /**
     * Everyone's day rates, or one person's: by user id, then newest first.
     *
     * @return list<DayRate>
     */
    public function findAll(?Uuid $userId = null): array;

    /**
     * Day rates on the dates of the period, everyone's or one person's, in any order.
     *
     * @return list<DayRate>
     */
    public function findIn(BusinessPeriod $period, ?Uuid $userId = null): array;

    /** @throws PayRateDateTakenException when the person got a day rate on that date in the meantime */
    public function add(DayRate $rate): void;

    /**
     * Persists changes made to an already managed day rate.
     *
     * @throws PayRateDateTakenException when the person got a day rate on the new date in the meantime
     */
    public function save(DayRate $rate): void;

    public function remove(DayRate $rate): void;
}
