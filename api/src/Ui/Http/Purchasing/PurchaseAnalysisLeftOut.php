<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

/**
 * How many purchased items of the period the analysis leaves out, for the screen to say so. Items of
 * excluded products are not counted: they are left out on purpose.
 */
final readonly class PurchaseAnalysisLeftOut
{
    public function __construct(
        /** Items of invoices in another currency than the organization's: in no figure. */
        public int $otherCurrency,
        /** Items with neither a net value nor the gross value and VAT to derive it: not in any net figure. */
        public int $withoutNet,
        /** Items with neither a gross value nor the net value and VAT to derive it: not in any gross figure. */
        public int $withoutGross,
        /** Items of products with a unit whose package size is unknown: in the spend, but not in quantities or prices. */
        public int $withoutPackageSize,
    ) {
    }
}
