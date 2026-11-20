<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetPurchaseInvoiceDocument;

use App\Expenses\Domain\Model\PurchaseInvoiceDocument;

final readonly class PurchaseInvoiceDocumentView
{
    public function __construct(
        public string $invoiceId,
        /** Unchanged, as received. */
        public string $content,
        public string $format,
        public string $sha256,
        public \DateTimeImmutable $receivedAt,
    ) {
    }

    public static function fromDocument(PurchaseInvoiceDocument $document): self
    {
        return new self(
            $document->getPurchaseInvoice()->getId()->toRfc4122(),
            $document->getContent(),
            $document->getFormat(),
            $document->getSha256(),
            $document->getReceivedAt(),
        );
    }
}
