<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayCloseForm;

final readonly class DayCloseFormView
{
    /**
     * @param list<DayCloseFormOptionView> $storages       active, by name
     * @param list<DayCloseFormOptionView> $denominations  active, of the currency, by value
     * @param list<DayCloseFormOptionView> $registers      active, by name
     * @param list<DayCloseFormOptionView> $paymentMethods active and not cash, in the organization's order
     */
    public function __construct(
        public string $currency,
        /** Whether a close can be made: at least one active storage and one active denomination. */
        public bool $canClose,
        /** Y-m-d: the date a close made now gets when none is picked. */
        public string $proposedDate,
        /** Y-m-d: today in the organization's time zone. */
        public string $today,
        public string $mode,
        /** HH:MM. */
        public string $dayEndsAt,
        public array $storages,
        public array $denominations,
        public array $registers,
        /** The only active register, else the latest close's if it is active, else null. */
        public ?string $preselectedRegisterId,
        public array $paymentMethods,
        /** Whether the proposed date is already closed, by whom and when; null when it is not. */
        public ?DayCloseFormClosedView $proposedDateClosed,
    ) {
    }
}
