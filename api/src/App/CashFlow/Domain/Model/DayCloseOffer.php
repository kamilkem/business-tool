<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

/**
 * What an end-of-day close covers when it is made (ADR 0033 §6, §8, §9): the active storages and
 * the active denominations of the organization's currency (Cash flow's), and the active registers and
 * active non-cash payment methods, and whether a payment method is marked as cash (Income's, asked
 * through its queries). The close form shows it, and DayClose::record() checks a close against it.
 */
final readonly class DayCloseOffer
{
    /**
     * @param list<CashStorage>     $storages        active
     * @param list<Denomination>    $denominations   active, in $currency
     * @param array<string, string> $registers       active: name by id
     * @param array<string, string> $nonCashMethods  active and not cash: name by id, in the organization's order
     * @param bool                  $cashMethod      whether an active payment method is marked as cash: the one Income books the cash income to
     */
    public function __construct(
        public string $currency,
        public array $storages,
        public array $denominations,
        public array $registers,
        public array $nonCashMethods,
        public bool $cashMethod,
    ) {
    }

    /** The time clock offers the close only while something can be counted (ADR 0033 Consequences). */
    public function canClose(): bool
    {
        return [] !== $this->storages && [] !== $this->denominations;
    }

    /** Income books a close into payment lines: the cash method's, or else at least one non-cash method's (ADR 0033 §9). */
    public function canBook(): bool
    {
        return $this->cashMethod || [] !== $this->nonCashMethods;
    }
}
