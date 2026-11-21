<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryPaymentMethodRepository implements PaymentMethodRepositoryInterface
{
    /** @var array<string, PaymentMethod> */
    private array $byId = [];

    public int $saveAllCallCount = 0;

    public function findById(Uuid $id): ?PaymentMethod
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByName(string $name): ?PaymentMethod
    {
        foreach ($this->byId as $method) {
            if ($method->getName() === $name) {
                return $method;
            }
        }

        return null;
    }

    public function findAll(bool $includeDeactivated = false): array
    {
        $methods = array_values(array_filter($this->byId, static fn (PaymentMethod $method): bool => $includeDeactivated || $method->isActive()));
        usort($methods, PaymentMethod::compare(...));

        return $methods;
    }

    public function nextPosition(): int
    {
        return [] === $this->byId ? 0 : max(array_map(static fn (PaymentMethod $method): int => $method->getPosition(), $this->byId)) + 1;
    }

    public function add(PaymentMethod $method): void
    {
        $this->byId[$method->getId()->toRfc4122()] = $method;
    }

    public function save(PaymentMethod $method): void
    {
    }

    public function saveAll(array $methods): void
    {
        ++$this->saveAllCallCount;
    }

    public function remove(PaymentMethod $method): void
    {
        unset($this->byId[$method->getId()->toRfc4122()]);
    }
}
