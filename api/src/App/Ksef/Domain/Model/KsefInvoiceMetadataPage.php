<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/**
 * One page of purchase invoice metadata. Continue with the next `pageOffset` while `hasMore`; when
 * `isTruncated` (10,000 records per filter set) start a new query from the last record's
 * `permanentStorageDate` at offset 0. Below `permanentStorageHwmDate` the results no longer change
 * (ADR 0023 §4).
 */
final readonly class KsefInvoiceMetadataPage
{
    /** @param list<KsefInvoiceMetadata> $invoices */
    public function __construct(
        public array $invoices,
        public bool $hasMore,
        public bool $isTruncated,
        public ?\DateTimeImmutable $permanentStorageHwmDate,
    ) {
    }
}
