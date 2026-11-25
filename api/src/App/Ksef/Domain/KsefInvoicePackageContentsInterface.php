<?php

declare(strict_types=1);

namespace App\Ksef\Domain;

use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\Model\KsefInvoiceMetadata;

/**
 * A downloaded and decrypted export package, read invoice by invoice (ADR 0039 §5). It holds a
 * temporary file until close(), which the caller calls when done, also after a failure.
 */
interface KsefInvoicePackageContentsInterface
{
    /**
     * The package's `_metadata.json` records, in ascending permanent-storage order.
     *
     * @return list<KsefInvoiceMetadata>
     */
    public function invoices(): array;

    /**
     * The XML of one invoice of the package, exactly as KSeF stores it.
     *
     * @throws KsefRequestFailedException when the package holds no XML for it
     */
    public function xmlOf(string $ksefNumber): string;

    public function close(): void;
}
