<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Ksef;

use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\KsefInvoicePackageContentsInterface;
use App\Ksef\Domain\Model\KsefInvoiceMetadata;

/** An opened export package of InMemoryKsefClient (adr/0039). */
final class InMemoryInvoicePackage implements KsefInvoicePackageContentsInterface
{
    private bool $closed = false;

    /**
     * @param list<KsefInvoiceMetadata> $invoices
     * @param array<string, string>     $xml      KSeF number => XML
     * @param \Closure(): void          $onClose
     */
    public function __construct(
        private readonly array $invoices,
        private readonly array $xml,
        private readonly \Closure $onClose,
    ) {
    }

    public function invoices(): array
    {
        return $this->invoices;
    }

    public function xmlOf(string $ksefNumber): string
    {
        return $this->xml[$ksefNumber] ?? throw KsefRequestFailedException::malformedResponse('invoice package', \sprintf('it holds no XML for %s', $ksefNumber));
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            ($this->onClose)();
        }
    }
}
