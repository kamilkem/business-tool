<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Repository;

use App\CashFlow\Domain\Exception\DenominationInUseException;
use App\CashFlow\Domain\Exception\DenominationValueTakenException;
use App\CashFlow\Domain\Model\Denomination;
use Symfony\Component\Uid\Uuid;

/** Denominations of the current organization only (TenantFilter). */
interface DenominationRepositoryInterface
{
    public function findById(Uuid $id): ?Denomination;

    /** The denomination with this currency and value, deactivated or not: for Denomination::create(). */
    public function findByValue(string $currency, int $value): ?Denomination;

    /**
     * The denominations of a currency, active first, each group by value (Denomination::compare()).
     *
     * @return list<Denomination>
     */
    public function findByCurrency(string $currency, bool $includeDeactivated = false): array;

    /** Whether a close or movement uses the denomination. */
    public function isUsed(Uuid $id): bool;

    /** @return list<string> the ids of the denominations that a close or movement uses */
    public function usedIds(): array;

    /** @throws DenominationValueTakenException when a denomination with the value was stored in the meantime */
    public function add(Denomination $denomination): void;

    /** Persists changes made to an already managed denomination. */
    public function save(Denomination $denomination): void;

    /** @throws DenominationInUseException when a close or movement started using it in the meantime */
    public function remove(Denomination $denomination): void;
}
