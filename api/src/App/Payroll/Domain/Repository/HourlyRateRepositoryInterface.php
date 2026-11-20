<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Repository;

use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Model\HourlyRate;
use Symfony\Component\Uid\Uuid;

/** Hourly rates of the current organization only (TenantFilter). */
interface HourlyRateRepositoryInterface
{
    public function findById(Uuid $id): ?HourlyRate;

    /**
     * The person's rates, oldest first (for HourlyRateHistory).
     *
     * @return list<HourlyRate>
     */
    public function findByUser(Uuid $userId): array;

    /**
     * Everyone's rates, or one person's: by user id, then newest first.
     *
     * @return list<HourlyRate>
     */
    public function findAll(?Uuid $userId = null): array;

    /** @throws PayRateDateTakenException when the person got a rate from that date in the meantime */
    public function add(HourlyRate $rate): void;

    /**
     * Persists changes made to an already managed rate.
     *
     * @throws PayRateDateTakenException when the person got a rate from the new date in the meantime
     */
    public function save(HourlyRate $rate): void;

    public function remove(HourlyRate $rate): void;
}
