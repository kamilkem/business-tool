<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Domain\Analysis\AnalysisAmounts;

/** A spend in integer minor units of the organization's currency, on both bases (ADR 0041 §6); a correction's rows count negative. */
final readonly class PurchaseAnalysisAmounts
{
    public function __construct(
        /** Printed net, or gross less VAT; items with neither are left out (see `leftOut`). */
        public int $net,
        /** Printed gross, or net plus VAT; items with neither are left out (see `leftOut`). */
        public int $gross,
    ) {
    }

    public static function fromDomain(AnalysisAmounts $amounts): self
    {
        return new self($amounts->net, $amounts->gross);
    }
}
