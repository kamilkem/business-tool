<?php

declare(strict_types=1);

namespace App\Income\Domain\Repository;

use App\Income\Domain\Exception\RegisterInUseException;
use App\Income\Domain\Exception\RegisterNameTakenException;
use App\Income\Domain\Model\Register;
use Symfony\Component\Uid\Uuid;

/** Registers of the current organization only (TenantFilter). */
interface RegisterRepositoryInterface
{
    public function findById(Uuid $id): ?Register;

    /** The register with exactly this name, deactivated or not: for Register::create() and rename(). */
    public function findByName(string $name): ?Register;

    /**
     * Active registers first, then by name.
     *
     * @return list<Register>
     */
    public function findAll(bool $includeDeactivated = false): array;

    /** @throws RegisterNameTakenException when a register with the name was stored in the meantime */
    public function add(Register $register): void;

    /**
     * Persists changes made to an already managed register.
     *
     * @throws RegisterNameTakenException when a register with the new name was stored in the meantime
     */
    public function save(Register $register): void;

    /** @throws RegisterInUseException when a revenue entry was recorded for it in the meantime */
    public function remove(Register $register): void;
}
