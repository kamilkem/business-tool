<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Domain\Exception\RevenueEntryAlreadyExistsException;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryRevenueEntryRepository implements RevenueEntryRepositoryInterface
{
    /** @var array<string, RevenueEntry> */
    private array $byId = [];

    public int $saveCallCount = 0;

    public function findById(Uuid $id): ?RevenueEntry
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByRegisterAndDate(Uuid $registerId, BusinessDate $businessDate): ?RevenueEntry
    {
        foreach ($this->byId as $entry) {
            if ($entry->getRegister()->getId()->equals($registerId) && $entry->getBusinessDate()->equals($businessDate)) {
                return $entry;
            }
        }

        return null;
    }

    public function findInPeriod(BusinessPeriod $period, ?Uuid $registerId = null): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (RevenueEntry $entry): bool => $period->contains($entry->getBusinessDate())
                && (null === $registerId || $entry->getRegister()->getId()->equals($registerId)),
        ));
    }

    public function usesRegister(Uuid $registerId): bool
    {
        return \in_array($registerId->toRfc4122(), $this->usedRegisterIds(), true);
    }

    public function usedRegisterIds(): array
    {
        $ids = [];
        foreach ($this->byId as $entry) {
            $ids[$entry->getRegister()->getId()->toRfc4122()] = true;
        }

        return array_keys($ids);
    }

    public function usesPaymentMethod(Uuid $paymentMethodId): bool
    {
        return \in_array($paymentMethodId->toRfc4122(), $this->usedPaymentMethodIds(), true);
    }

    public function usedPaymentMethodIds(): array
    {
        $ids = [];
        foreach ($this->byId as $entry) {
            foreach ($entry->getPaymentLines() as $line) {
                $ids[$line->method->getId()->toRfc4122()] = true;
            }
        }

        return array_keys($ids);
    }

    public function add(RevenueEntry $entry): void
    {
        if (null !== $this->findByRegisterAndDate($entry->getRegister()->getId(), $entry->getBusinessDate())) {
            throw RevenueEntryAlreadyExistsException::for($entry->getRegister()->getId()->toRfc4122(), $entry->getBusinessDate()->toString());
        }

        $this->byId[$entry->getId()->toRfc4122()] = $entry;
    }

    public function save(RevenueEntry $entry): void
    {
        ++$this->saveCallCount;
    }

    public function remove(RevenueEntry $entry): void
    {
        unset($this->byId[$entry->getId()->toRfc4122()]);
    }

    /** @return list<RevenueEntry> */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
