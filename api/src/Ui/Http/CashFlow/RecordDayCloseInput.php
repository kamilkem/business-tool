<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * An end-of-day close (ADR 0033 §6). Whether it covers every active storage and non-cash payment
 * method, the denominations and the register are offered, and the date is allowed, the domain checks.
 */
final readonly class RecordDayCloseInput
{
    /**
     * @param list<DayCloseStorageInput> $storages
     * @param list<DayClosePaymentInput> $payments
     */
    public function __construct(
        /** An active register of the organization (GET /day-close-form). */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $registerId,
        /** Every active storage, once each. */
        #[Assert\NotNull]
        #[Assert\Unique(normalizer: [DayCloseStorageInput::class, 'storageOf'], message: 'Each storage may appear only once.')]
        #[Assert\Valid]
        public array $storages,
        /** Every active non-cash payment method, once each; zero allowed. */
        #[Assert\NotNull]
        #[Assert\Unique(normalizer: [DayClosePaymentInput::class, 'methodOf'], message: 'Each payment method may appear only once.')]
        #[Assert\Valid]
        public array $payments,
        /** YYYY-MM-DD; the proposed date when left out. An employee in `day_end_time` mode sends none or the proposed one, in `employee_picks` mode today or yesterday; owners and managers any date up to today. */
        #[Assert\Date]
        public ?string $date = null,
    ) {
    }
}
