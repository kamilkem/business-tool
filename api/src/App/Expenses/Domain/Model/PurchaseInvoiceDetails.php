<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

use App\Shared\Domain\Time\BusinessDate;

/**
 * What a purchase invoice says, as an importer or a person (#54) gives it; the aggregate
 * checks it when it takes it (PurchaseInvoice). Amounts are minor units of `$currency` and may be
 * negative. Totals are stored as given: they are not checked against each other or the lines
 * (an advance or a correction need not add up).
 */
final readonly class PurchaseInvoiceDetails
{
    /**
     * @param ?string                        $invoiceNumber       required for an imported invoice; optional for a manual one (a receipt may have none)
     * @param ?string                        $sellerTaxId         required for an imported invoice; optional for a manual one (a foreign seller may have none)
     * @param list<PurchaseInvoiceVatAmount> $vatLines           totals per VAT rate; may be empty
     * @param ?string                        $correctedExternalId for a correction, the corrected invoice's id in the same source when given
     */
    public function __construct(
        public ?string $invoiceNumber,
        public ?string $sellerTaxId,
        public string $sellerName,
        public BusinessDate $issueDate,
        public PurchaseInvoiceKind $kind,
        public string $currency,
        public int $netTotal,
        public int $vatTotal,
        public int $grossTotal,
        public array $vatLines = [],
        public ?string $correctedExternalId = null,
    ) {
    }
}
