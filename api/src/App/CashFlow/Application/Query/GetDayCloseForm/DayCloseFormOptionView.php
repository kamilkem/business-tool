<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayCloseForm;

/** A storage, denomination, register or payment method offered by the close form. */
final readonly class DayCloseFormOptionView
{
    public function __construct(
        public string $id,
        /** A storage's, register's or payment method's name; null for a denomination. */
        public ?string $name,
        /** A storage's mode (`counted`, `deposit`) or a denomination's kind (`coin`, `note`); null otherwise. */
        public ?string $kind,
        /** A denomination's face value in minor units; null otherwise. */
        public ?int $value,
    ) {
    }
}
