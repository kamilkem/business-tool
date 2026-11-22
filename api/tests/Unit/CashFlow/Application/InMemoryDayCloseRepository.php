<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Domain\Exception\DayAlreadyClosedException;
use App\CashFlow\Domain\Model\DayClose;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryDayCloseRepository implements DayCloseRepositoryInterface
{
    /** @var array<string, DayClose> */
    private array $byId = [];

    public function findById(Uuid $id): ?DayClose
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByDate(BusinessDate $date): ?DayClose
    {
        foreach ($this->byId as $close) {
            if ($close->getBusinessDate()->equals($date)) {
                return $close;
            }
        }

        return null;
    }

    public function findLatest(): ?DayClose
    {
        return $this->byDateDescending()[0] ?? null;
    }

    public function findInPeriod(BusinessPeriod $period): array
    {
        return array_values(array_filter($this->byDateDescending(), static fn (DayClose $close): bool => $period->contains($close->getBusinessDate())));
    }

    public function add(DayClose $close): void
    {
        if (null !== $this->findByDate($close->getBusinessDate())) {
            throw DayAlreadyClosedException::concurrently($close->getBusinessDate()->toString());
        }
        $this->byId[$close->getId()->toRfc4122()] = $close;
    }

    /** @return list<DayClose> */
    public function all(): array
    {
        return array_values($this->byId);
    }

    /** @return list<DayClose> */
    private function byDateDescending(): array
    {
        $closes = array_values($this->byId);
        usort($closes, static fn (DayClose $a, DayClose $b): int => $b->getBusinessDate()->toString() <=> $a->getBusinessDate()->toString());

        return $closes;
    }
}
