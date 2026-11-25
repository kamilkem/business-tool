<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetPurchaseInvoice;

use App\Expenses\Domain\Model\PurchaseInvoice;

final readonly class PurchaseInvoiceView
{
    /** @param list<PurchaseInvoiceVatLineView> $vatLines highest rate first, exempt last */
    public function __construct(
        public string $id,
        public string $source,
        public ?string $externalId,
        public ?string $invoiceNumber,
        public ?string $sellerTaxId,
        public string $sellerName,
        /** YYYY-MM-DD. */
        public string $issueDate,
        public string $kind,
        public ?string $correctedExternalId,
        public string $currency,
        public string $taxCurrency,
        public int $netTotal,
        public int $vatTotal,
        public int $grossTotal,
        public array $vatLines,
        /** The format of the original document; null when the invoice has none. */
        public ?string $documentFormat,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function of(PurchaseInvoice $invoice, ?string $documentFormat): self
    {
        return new self(
            $invoice->getId()->toRfc4122(),
            $invoice->getSource()->value,
            $invoice->getExternalId(),
            $invoice->getInvoiceNumber(),
            $invoice->getSellerTaxId(),
            $invoice->getSellerName(),
            $invoice->getIssueDate()->toString(),
            $invoice->getKind()->value,
            $invoice->getCorrectedExternalId(),
            $invoice->getCurrency(),
            $invoice->getTaxCurrency(),
            $invoice->getNetTotal(),
            $invoice->getVatTotal(),
            $invoice->getGrossTotal(),
            array_map(PurchaseInvoiceVatLineView::fromAmount(...), $invoice->getVatLines()),
            $documentFormat,
            $invoice->getCreatedAt(),
        );
    }
}
