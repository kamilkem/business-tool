<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\ImportPurchaseInvoice;

use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Shared\Application\Command\CommandInterface;

/**
 * Stores an invoice an importer brought in from its source (ADR 0024 §1–3), with its original document,
 * in the current organization (an importer's job runs inside it). Country-neutral: the importer names
 * its source (e.g. `ksef`), its own id for the invoice and the document's format (e.g. a form code).
 * There is no actor: importers run on the jobs worker.
 */
final readonly class ImportPurchaseInvoiceCommand implements CommandInterface
{
    /**
     * @param string                                                                      $issueDate Y-m-d
     * @param list<array{rate: string, net: int, vat: int, vatInTaxCurrency: int|null}> $vatLines
     */
    public function __construct(
        public string $organizationId,
        public string $invoiceId,
        public string $source,
        public string $externalId,
        public ?string $invoiceNumber,
        public ?string $sellerTaxId,
        public string $sellerName,
        public string $issueDate,
        public string $kind,
        public string $currency,
        public int $netTotal,
        public int $vatTotal,
        public int $grossTotal,
        public array $vatLines,
        public ?string $correctedExternalId,
        public string $documentContent,
        public string $documentFormat,
    ) {
    }

    /** The command for what a parser read from the document. */
    public static function of(
        string $organizationId,
        string $invoiceId,
        string $source,
        string $externalId,
        PurchaseInvoiceDetails $details,
        string $documentContent,
        string $documentFormat,
    ): self {
        return new self(
            $organizationId,
            $invoiceId,
            $source,
            $externalId,
            $details->invoiceNumber,
            $details->sellerTaxId,
            $details->sellerName,
            $details->issueDate->toString(),
            $details->kind->value,
            $details->currency,
            $details->netTotal,
            $details->vatTotal,
            $details->grossTotal,
            array_map(static fn (PurchaseInvoiceVatAmount $line): array => [
                'rate' => $line->rate,
                'net' => $line->net,
                'vat' => $line->vat,
                'vatInTaxCurrency' => $line->vatInTaxCurrency,
            ], $details->vatLines),
            $details->correctedExternalId,
            $documentContent,
            $documentFormat,
        );
    }
}
