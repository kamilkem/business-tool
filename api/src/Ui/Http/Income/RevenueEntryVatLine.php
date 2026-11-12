<?php

declare(strict_types=1);

namespace Ui\Http\Income;

/** One line of the VAT breakdown as printed, in minor units. */
final readonly class RevenueEntryVatLine
{
    public function __construct(
        /** A rate code of GET /vat-rates: "23", "zw". */
        public string $rate,
        public int $gross,
        public int $vat,
        /** gross − vat. */
        public int $net,
    ) {
    }
}
