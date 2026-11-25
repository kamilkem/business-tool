<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/**
 * A finished export package (ADR 0039 §5). When `isTruncated` (10,000 invoices or 1 GB), the next
 * export starts at `lastPermanentStorageDate`; otherwise everything below `permanentStorageHwmDate`
 * (or the requested end, if earlier) is in it.
 */
final readonly class KsefInvoicePackage
{
    /** @param list<KsefInvoicePackagePart> $parts in their ordinal order */
    public function __construct(
        public int $invoiceCount,
        public array $parts,
        public bool $isTruncated,
        public ?\DateTimeImmutable $lastPermanentStorageDate,
        public ?\DateTimeImmutable $permanentStorageHwmDate,
    ) {
    }
}
