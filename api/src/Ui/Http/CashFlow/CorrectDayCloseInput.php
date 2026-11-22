<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The correction of an end-of-day close (ADR 0033 §7): everything the close records except its date.
 * Whether it covers the close's storages and non-cash payment methods, the denominations are offered
 * and the register is allowed, the domain checks.
 */
final readonly class CorrectDayCloseInput
{
    /**
     * @param list<DayCloseStorageInput> $storages
     * @param list<DayClosePaymentInput> $payments
     */
    public function __construct(
        /** The close's register, or another active register of the organization. */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $registerId,
        /** Every storage of the close, once each. */
        #[Assert\NotNull]
        #[Assert\Unique(normalizer: [DayCloseStorageInput::class, 'storageOf'], message: 'Each storage may appear only once.')]
        #[Assert\Valid]
        public array $storages,
        /** Every non-cash payment method of the close, once each; zero allowed. */
        #[Assert\NotNull]
        #[Assert\Unique(normalizer: [DayClosePaymentInput::class, 'methodOf'], message: 'Each payment method may appear only once.')]
        #[Assert\Valid]
        public array $payments,
    ) {
    }
}
