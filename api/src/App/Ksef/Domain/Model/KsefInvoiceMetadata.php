<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

use App\Shared\Domain\Time\BusinessDate;

/**
 * What the sync needs from one record of `POST /invoices/query/metadata`. Amounts are left out on
 * purpose: they are doubles and the import takes them from the XML (ADR 0023 §7).
 */
final readonly class KsefInvoiceMetadata
{
    /**
     * @param string $invoiceHash SHA-256 of the invoice XML, Base64
     */
    public function __construct(
        public string $ksefNumber,
        public KsefFormCode $formCode,
        public string $invoiceHash,
        public \DateTimeImmutable $permanentStorageDate,
        /** The invoice's issue date as KSeF reports it (`P_1`); the import starts at a date of issue (ADR 0038). */
        public BusinessDate $issueDate,
    ) {
    }
}
