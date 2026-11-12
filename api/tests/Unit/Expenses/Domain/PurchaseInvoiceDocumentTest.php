<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Domain;

use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Shared\Domain\Time\BusinessDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PurchaseInvoiceDocument::class)]
final class PurchaseInvoiceDocumentTest extends TestCase
{
    private const string XML = "<?xml version=\"1.0\"?>\n<Faktura>  </Faktura>\n";

    public function test_it_keeps_the_content_unchanged_with_its_sha256(): void
    {
        $invoice = $this->invoice();
        $receivedAt = new \DateTimeImmutable('2026-11-12 21:00:00');

        $document = PurchaseInvoiceDocument::of($invoice, self::XML, ' FA (3) ', $receivedAt);

        self::assertSame($invoice, $document->getPurchaseInvoice());
        self::assertSame(self::XML, $document->getContent());
        self::assertSame('FA (3)', $document->getFormat());
        self::assertSame(hash('sha256', self::XML), $document->getSha256());
        self::assertSame($receivedAt, $document->getReceivedAt());
    }

    public function test_it_rejects_empty_content(): void
    {
        $this->expectException(InvalidPurchaseInvoiceException::class);

        PurchaseInvoiceDocument::of($this->invoice(), " \n", 'FA (3)', new \DateTimeImmutable());
    }

    public function test_it_rejects_a_blank_format(): void
    {
        $this->expectException(InvalidPurchaseInvoiceException::class);

        PurchaseInvoiceDocument::of($this->invoice(), self::XML, ' ', new \DateTimeImmutable());
    }

    public function test_it_rejects_a_format_too_long(): void
    {
        $this->expectException(InvalidPurchaseInvoiceException::class);

        PurchaseInvoiceDocument::of($this->invoice(), self::XML, str_repeat('F', 33), new \DateTimeImmutable());
    }

    private function invoice(): PurchaseInvoice
    {
        $details = new PurchaseInvoiceDetails('FV/1', '5265877635', 'Hurtownia', BusinessDate::fromString('2026-11-10'), PurchaseInvoiceKind::Regular, 'PLN', 100, 23, 123);

        return PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, 'K-1', $details, 'PL', 'PLN', new \DateTimeImmutable('2026-11-12'));
    }
}
