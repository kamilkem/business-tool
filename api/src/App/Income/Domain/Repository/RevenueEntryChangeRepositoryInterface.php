<?php

declare(strict_types=1);

namespace App\Income\Domain\Repository;

use App\Income\Domain\Model\RevenueEntryChange;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

/** The append-only change history of revenue entries (ADR 0034 §4): rows are added, never changed or removed. */
interface RevenueEntryChangeRepositoryInterface
{
    public function add(RevenueEntryChange $change): void;

    /** @return list<RevenueEntryChange> one entry's changes, newest first */
    public function findByEntryId(Uuid $entryId): array;

    /** @return list<RevenueEntryChange> the changes of entries dated in the period, newest first */
    public function findInPeriod(BusinessPeriod $period): array;
}
