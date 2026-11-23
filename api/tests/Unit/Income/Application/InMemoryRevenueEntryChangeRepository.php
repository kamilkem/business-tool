<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Domain\Model\RevenueEntryChange;
use App\Income\Domain\Repository\RevenueEntryChangeRepositoryInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

final class InMemoryRevenueEntryChangeRepository implements RevenueEntryChangeRepositoryInterface
{
    /** @var list<RevenueEntryChange> in the order added */
    private array $changes = [];

    public function add(RevenueEntryChange $change): void
    {
        $this->changes[] = $change;
    }

    public function findByEntryId(Uuid $entryId): array
    {
        return $this->newestFirst(array_filter($this->changes, static fn (RevenueEntryChange $change): bool => $change->getEntryId()->equals($entryId)));
    }

    public function findInPeriod(BusinessPeriod $period): array
    {
        return $this->newestFirst(array_filter($this->changes, static fn (RevenueEntryChange $change): bool => $period->contains($change->getBusinessDate())));
    }

    /** @return list<RevenueEntryChange> */
    public function all(): array
    {
        return $this->changes;
    }

    /**
     * @param array<int, RevenueEntryChange> $changes
     *
     * @return list<RevenueEntryChange>
     */
    private function newestFirst(array $changes): array
    {
        return array_reverse(array_values($changes));
    }
}
