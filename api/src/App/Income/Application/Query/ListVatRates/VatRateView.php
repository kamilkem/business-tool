<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListVatRates;

final readonly class VatRateView
{
    public function __construct(
        /** The rate as a fiscal printer labels it: "23", "zw". */
        public string $code,
        /** Sales at this rate carry no VAT (0% and exempt): their VAT must be 0. */
        public bool $zeroVat,
    ) {
    }
}
