<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payroll\Application;

use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Model\HourlyRate;
use App\Payroll\Domain\Repository\HourlyRateRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final class InMemoryHourlyRateRepository implements HourlyRateRepositoryInterface
{
    /** @var array<string, HourlyRate> */
    private array $byId = [];

    public function findById(Uuid $id): ?HourlyRate
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByUser(Uuid $userId): array
    {
        $rates = array_values(array_filter($this->byId, static fn (HourlyRate $rate): bool => $rate->getUserId()->equals($userId)));
        usort($rates, static fn (HourlyRate $a, HourlyRate $b): int => $a->getEffectiveFrom()->toString() <=> $b->getEffectiveFrom()->toString());

        return $rates;
    }

    public function findAll(?Uuid $userId = null): array
    {
        $rates = array_values(array_filter($this->byId, static fn (HourlyRate $rate): bool => null === $userId || $rate->getUserId()->equals($userId)));
        usort($rates, static fn (HourlyRate $a, HourlyRate $b): int => [$a->getUserId()->toRfc4122(), $b->getEffectiveFrom()->toString()] <=> [$b->getUserId()->toRfc4122(), $a->getEffectiveFrom()->toString()]);

        return $rates;
    }

    public function add(HourlyRate $rate): void
    {
        $this->assertUnique($rate);
        $this->byId[$rate->getId()->toRfc4122()] = $rate;
    }

    public function save(HourlyRate $rate): void
    {
        $this->assertUnique($rate);
    }

    public function remove(HourlyRate $rate): void
    {
        unset($this->byId[$rate->getId()->toRfc4122()]);
    }

    private function assertUnique(HourlyRate $rate): void
    {
        foreach ($this->byId as $other) {
            if (!$other->getId()->equals($rate->getId()) && $other->getUserId()->equals($rate->getUserId()) && $other->getEffectiveFrom()->equals($rate->getEffectiveFrom())) {
                throw PayRateDateTakenException::hourlyRate($rate->getUserId()->toRfc4122(), $rate->getEffectiveFrom());
            }
        }
    }
}
