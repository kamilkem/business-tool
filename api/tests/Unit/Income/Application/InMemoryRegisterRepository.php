<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Domain\Model\Register;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryRegisterRepository implements RegisterRepositoryInterface
{
    /** @var array<string, Register> */
    private array $byId = [];

    public function findById(Uuid $id): ?Register
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByName(string $name): ?Register
    {
        foreach ($this->byId as $register) {
            if ($register->getName() === $name) {
                return $register;
            }
        }

        return null;
    }

    public function findAll(bool $includeDeactivated = false): array
    {
        $registers = array_values(array_filter($this->byId, static fn (Register $register): bool => $includeDeactivated || $register->isActive()));
        usort($registers, static fn (Register $a, Register $b): int => [!$a->isActive(), $a->getName()] <=> [!$b->isActive(), $b->getName()]);

        return $registers;
    }

    public function add(Register $register): void
    {
        $this->byId[$register->getId()->toRfc4122()] = $register;
    }

    public function save(Register $register): void
    {
    }
}
