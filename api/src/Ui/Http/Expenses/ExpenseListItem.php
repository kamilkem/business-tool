<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;

/** An expense in GET /expenses: the fields of GET /expenses/{id} without its items (ADR 0040). */
final readonly class ExpenseListItem
{
    /** @param list<ExpenseVatLine> $vatLines */
    public function __construct(
        public string $id,
        /** Where the expense came from: `ksef` (imported) or `manual`. */
        public string $source,
        /** The invoice's id in its source (for KSeF, the KSeF number); null for a manual expense. */
        public ?string $externalId,
        /** Null for a manual expense without one (a receipt). */
        public ?string $invoiceNumber,
        /** Null for a manual expense whose seller has none. */
        public ?string $sellerTaxId,
        public string $sellerName,
        /** The issue date, `YYYY-MM-DD`. */
        public string $issueDate,
        /** `regular`, `correction`, `advance`, `settlement`, `simplified`, `advance_correction` or `settlement_correction`. */
        public string $kind,
        /** For a correction: the corrected invoice's id in the same source, when the correction gives it. */
        public ?string $correctedExternalId,
        /** ISO 4217 code of the amounts. */
        public string $currency,
        /** ISO 4217 code VAT is settled in: the organization's currency when the expense was stored. */
        public string $taxCurrency,
        public int $netTotal,
        public int $vatTotal,
        public int $grossTotal,
        /** The VAT breakdown as printed, highest rate first, exempt last; may be empty. */
        public array $vatLines,
        /** True when the original document is stored (GET /expenses/{id}/document). */
        public bool $hasDocument,
        /** What the document is, as its importer named it (for KSeF, the FA form code); null without a document. */
        public ?string $documentFormat,
        /** When the expense was imported or entered. */
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromView(PurchaseInvoiceView $view): self
    {
        return new self(
            id: $view->id,
            source: $view->source,
            externalId: $view->externalId,
            invoiceNumber: $view->invoiceNumber,
            sellerTaxId: $view->sellerTaxId,
            sellerName: $view->sellerName,
            issueDate: $view->issueDate,
            kind: $view->kind,
            correctedExternalId: $view->correctedExternalId,
            currency: $view->currency,
            taxCurrency: $view->taxCurrency,
            netTotal: $view->netTotal,
            vatTotal: $view->vatTotal,
            grossTotal: $view->grossTotal,
            vatLines: array_map(ExpenseVatLine::fromView(...), $view->vatLines),
            hasDocument: null !== $view->documentFormat,
            documentFormat: $view->documentFormat,
            createdAt: $view->createdAt,
        );
    }
}
