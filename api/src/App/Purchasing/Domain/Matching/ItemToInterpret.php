<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

/**
 * What is sent to the interpreter about an item (ADR 0042 §6): its name, unit and the seller's code.
 * Nothing else leaves the system: no prices, quantities, dates, invoice numbers or seller names.
 */
final readonly class ItemToInterpret
{
    public function __construct(
        /** The caller's handle for this item; the answer refers to it. */
        public string $key,
        public string $name,
        public ?string $unit,
        public ?string $sellerCode,
    ) {
    }
}
