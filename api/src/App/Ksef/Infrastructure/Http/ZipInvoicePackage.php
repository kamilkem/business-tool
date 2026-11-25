<?php

declare(strict_types=1);

namespace App\Ksef\Infrastructure\Http;

use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\KsefInvoicePackageContentsInterface;
use App\Ksef\Domain\Model\KsefInvoiceMetadata;

/**
 * A decrypted export package in a temporary ZIP file (ADR 0039 §5): `{ksefNumber}.xml` per invoice,
 * read one entry at a time, and the records of `_metadata.json`. close() deletes the file.
 */
final class ZipInvoicePackage implements KsefInvoicePackageContentsInterface
{
    private bool $closed = false;

    /** @param list<KsefInvoiceMetadata> $invoices in ascending permanent-storage order */
    public function __construct(
        private readonly \ZipArchive $zip,
        private readonly string $path,
        private readonly array $invoices,
    ) {
    }

    public function invoices(): array
    {
        return $this->invoices;
    }

    public function xmlOf(string $ksefNumber): string
    {
        $xml = $this->closed ? false : $this->zip->getFromName($ksefNumber.'.xml');
        if (!\is_string($xml) || '' === $xml) {
            throw KsefRequestFailedException::malformedResponse('invoice package', \sprintf('it holds no XML for %s', $ksefNumber));
        }

        return $xml;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->zip->close();
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
