<?php

declare(strict_types=1);

namespace App\Income\Domain\Repository;

use App\Income\Domain\Exception\CashPaymentMethodTakenException;
use App\Income\Domain\Exception\PaymentMethodInUseException;
use App\Income\Domain\Exception\PaymentMethodNameTakenException;
use App\Income\Domain\Model\PaymentMethod;
use Symfony\Component\Uid\Uuid;

/** Payment methods of the current organization only (TenantFilter). */
interface PaymentMethodRepositoryInterface
{
    public function findById(Uuid $id): ?PaymentMethod;

    /** The method with exactly this name, deactivated or not: for PaymentMethod::create() and rename(). */
    public function findByName(string $name): ?PaymentMethod;

    /**
     * The active method marked as cash, if any: there is at most one (PaymentMethod::changeCash()).
     *
     * @phpstan-impure
     */
    public function findActiveCash(): ?PaymentMethod;

    /**
     * Active methods first, each group by position (PaymentMethod::compare()).
     *
     * @return list<PaymentMethod>
     */
    public function findAll(bool $includeDeactivated = false): array;

    /** A position after every method of the organization, active or not. */
    public function nextPosition(): int;

    /**
     * @throws PaymentMethodNameTakenException when a method with the name was stored in the meantime
     * @throws CashPaymentMethodTakenException when another active cash method was stored in the meantime
     */
    public function add(PaymentMethod $method): void;

    /**
     * Persists changes made to an already managed method.
     *
     * @throws PaymentMethodNameTakenException when a method with the new name was stored in the meantime
     * @throws CashPaymentMethodTakenException when another active cash method was stored in the meantime
     */
    public function save(PaymentMethod $method): void;

    /**
     * Persists a new order of already managed methods at once (PaymentMethodOrder::apply()).
     *
     * @param list<PaymentMethod> $methods
     */
    public function saveAll(array $methods): void;

    /** @throws PaymentMethodInUseException when a revenue entry got a line for it in the meantime */
    public function remove(PaymentMethod $method): void;
}
