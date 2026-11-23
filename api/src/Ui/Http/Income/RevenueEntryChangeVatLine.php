<?php

declare(strict_types=1);

namespace Ui\Http\Income;

/** A VAT line as it was. */
final readonly class RevenueEntryChangeVatLine
{
    public function __construct(
        /** A rate code: "23", "zw". */
        public string $rate,
        public int $gross,
        public int $vat,
    ) {
    }
}
