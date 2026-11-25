<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetPurchaseAnalysis;

use App\Purchasing\Domain\Analysis\AnalysisAmounts;
use App\Purchasing\Domain\Analysis\PurchaseAnalysisData;

final readonly class PurchaseAnalysisView
{
    public function __construct(
        /** First issue date of the period, `Y-m-d`. */
        public string $from,
        /** Last issue date of the period, inclusive, `Y-m-d`. */
        public string $to,
        /** ISO 4217 code of the organization's currency, the only one counted. */
        public string $currency,
        public PurchaseAnalysisData $analysis,
        /** Everything counted: the spend of every product and of the items not linked to one. */
        public AnalysisAmounts $total,
        /** The spend of the products that are in no category. */
        public AnalysisAmounts $uncategorizedSpend,
        /** How many of the listed products are in no category. */
        public int $uncategorizedProducts,
    ) {
    }
}
