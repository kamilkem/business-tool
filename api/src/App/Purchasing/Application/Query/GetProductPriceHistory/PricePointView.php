<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProductPriceHistory;

use App\Purchasing\Domain\Analysis\PricePoint;

final readonly class PricePointView
{
    public function __construct(
        public PricePoint $point,
        /** The invoice's number as Expenses has it now; null for an invoice without one. */
        public ?string $invoiceNumber,
    ) {
    }
}
