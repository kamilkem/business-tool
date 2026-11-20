<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Shared\Domain\Time\BusinessDate;
use Symfony\Component\Uid\Uuid;

/** Purchase invoices imported from KSeF for a Polish organization (tax currency PLN), with sensible defaults. */
final class PurchaseInvoiceMother
{
    public const string IMPORTED_AT = '2026-11-12 21:00:00';

    /**
     * @param array{int, int, int}           $totals   net, VAT, gross
     * @param list<PurchaseInvoiceVatAmount> $vatLines
     */
    public static function imported(
        ?string $externalId = null,
        string $issueDate = '2026-11-10',
        string $sellerName = 'Hurtownia Napojów',
        string $sellerTaxId = '5265877635',
        string $invoiceNumber = 'FV/1/2026',
        PurchaseInvoiceKind $kind = PurchaseInvoiceKind::Regular,
        string $currency = 'PLN',
        array $totals = [20_000, 4_600, 24_600],
        array $vatLines = [new PurchaseInvoiceVatAmount('23', 20_000, 4_600)],
        ?string $correctedExternalId = null,
        string $importedAt = self::IMPORTED_AT,
    ): PurchaseInvoice {
        $details = new PurchaseInvoiceDetails($invoiceNumber, $sellerTaxId, $sellerName, BusinessDate::fromString($issueDate), $kind, $currency, $totals[0], $totals[1], $totals[2], $vatLines, $correctedExternalId);

        return PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, $externalId ?? 'K-'.Uuid::v4()->toBase58(), $details, 'PL', 'PLN', new \DateTimeImmutable($importedAt));
    }
}
