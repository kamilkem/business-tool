<?php

declare(strict_types=1);

namespace App\Income\Domain\Repository;

use App\Income\Domain\Exception\RevenueEntryAlreadyExistsException;
use App\Income\Domain\Model\RevenueEntry;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

/** Revenue entries of the current organization only (TenantFilter), with their lines. */
interface RevenueEntryRepositoryInterface
{
    public function findById(Uuid $id): ?RevenueEntry;

    public function findByRegisterAndDate(Uuid $registerId, BusinessDate $businessDate): ?RevenueEntry;

    /**
     * Entries whose business date is in the period (both ends included), of one register or of all:
     * by business date, then by register name.
     *
     * @return list<RevenueEntry>
     */
    public function findInPeriod(BusinessPeriod $period, ?Uuid $registerId = null): array;

    /**
     * @throws RevenueEntryAlreadyExistsException when the register already has an entry for the
     *                                            business date (the unique constraint caught it)
     */
    public function add(RevenueEntry $entry): void;

    /** Persists changes made to an already managed entry, its lines included. */
    public function save(RevenueEntry $entry): void;

    /** Deletes the entry and its lines (ADR 0022 §8: no trace is kept). */
    public function remove(RevenueEntry $entry): void;
}
