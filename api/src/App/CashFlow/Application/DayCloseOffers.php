<?php

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Domain\Model\DayCloseOffer;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use App\Income\Application\Query\GetRegister\RegisterView;
use App\Income\Application\Query\ListBookablePaymentMethods\ListBookablePaymentMethodsQuery;
use App\Income\Application\Query\ListBookableRegisters\ListBookableRegistersQuery;
use App\Shared\Application\Query\QueryBusInterface;

/**
 * What an end-of-day close of the current organization covers now (ADR 0033 §6): Cash flow's active
 * storages and denominations, and Income's active registers and non-cash payment methods, asked
 * through Income's queries without an actor check (§9).
 */
final readonly class DayCloseOffers
{
    public function __construct(
        private CashStorageRepositoryInterface $storages,
        private DenominationRepositoryInterface $denominations,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function current(CashFlowActor $actor): DayCloseOffer
    {
        /** @var list<RegisterView> $registers */
        $registers = $this->queryBus->ask(new ListBookableRegistersQuery());
        /** @var list<PaymentMethodView> $methods */
        $methods = $this->queryBus->ask(new ListBookablePaymentMethodsQuery());

        $registerNames = [];
        foreach ($registers as $register) {
            $registerNames[$register->id] = $register->name;
        }
        $nonCash = [];
        foreach ($methods as $method) {
            if (!$method->cash) {
                $nonCash[$method->id] = $method->name;
            }
        }

        $currency = $actor->organization->currency;

        return new DayCloseOffer(
            $currency,
            $this->storages->findAll(),
            $this->denominations->findByCurrency($currency),
            $registerNames,
            $nonCash,
        );
    }
}
