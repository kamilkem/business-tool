<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payroll\Application;

use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Model\DayRate;
use App\Payroll\Domain\Repository\DayRateRepositoryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

final class InMemoryDayRateRepository implements DayRateRepositoryInterface
{
    /** @var array<string, DayRate> */
    private array $byId = [];

    public function findById(Uuid $id): ?DayRate
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByUserAndDate(Uuid $userId, BusinessDate $date): ?DayRate
    {
        foreach ($this->byId as $dayRate) {
            if ($dayRate->getUserId()->equals($userId) && $dayRate->getDate()->equals($date)) {
                return $dayRate;
            }
        }

        return null;
    }

    public function findAll(?Uuid $userId = null): array
    {
        $dayRates = array_values(array_filter($this->byId, static fn (DayRate $rate): bool => null === $userId || $rate->getUserId()->equals($userId)));
        usort($dayRates, static fn (DayRate $a, DayRate $b): int => [$a->getUserId()->toRfc4122(), $b->getDate()->toString()] <=> [$b->getUserId()->toRfc4122(), $a->getDate()->toString()]);

        return $dayRates;
    }

    public function findIn(BusinessPeriod $period, ?Uuid $userId = null): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (DayRate $rate): bool => $period->contains($rate->getDate()) && (null === $userId || $rate->getUserId()->equals($userId)),
        ));
    }

    public function add(DayRate $rate): void
    {
        $this->assertUnique($rate);
        $this->byId[$rate->getId()->toRfc4122()] = $rate;
    }

    public function save(DayRate $rate): void
    {
        $this->assertUnique($rate);
    }

    public function remove(DayRate $rate): void
    {
        unset($this->byId[$rate->getId()->toRfc4122()]);
    }

    private function assertUnique(DayRate $rate): void
    {
        $other = $this->findByUserAndDate($rate->getUserId(), $rate->getDate());
        if (null !== $other && !$other->getId()->equals($rate->getId())) {
            throw PayRateDateTakenException::dayRate($rate->getUserId()->toRfc4122(), $rate->getDate());
        }
    }
}
