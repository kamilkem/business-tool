<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command;

use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;

/**
 * What a person types in for a manual expense (#54), as the primitives the commands carry, turned
 * into the domain's PurchaseInvoiceDetails. The aggregate checks the rules.
 */
final class PurchaseInvoiceDetailsInput
{
    /**
     * @param list<array{rate: string, net: int, vat: int, vatInTaxCurrency: ?int}> $vatLines
     *
     * @throws InvalidBusinessDateException    when the issue date is not YYYY-MM-DD
     * @throws InvalidPurchaseInvoiceException when the kind is unknown
     */
    public static function toDetails(
        ?string $invoiceNumber,
        ?string $sellerTaxId,
        string $sellerName,
        string $issueDate,
        string $kind,
        string $currency,
        int $netTotal,
        int $vatTotal,
        int $grossTotal,
        array $vatLines,
    ): PurchaseInvoiceDetails {
        return new PurchaseInvoiceDetails(
            invoiceNumber: $invoiceNumber,
            sellerTaxId: $sellerTaxId,
            sellerName: $sellerName,
            issueDate: BusinessDate::fromString($issueDate),
            kind: PurchaseInvoiceKind::tryFrom($kind) ?? throw InvalidPurchaseInvoiceException::unknownKind($kind),
            currency: $currency,
            netTotal: $netTotal,
            vatTotal: $vatTotal,
            grossTotal: $grossTotal,
            vatLines: array_map(
                static fn (array $line): PurchaseInvoiceVatAmount => new PurchaseInvoiceVatAmount($line['rate'], $line['net'], $line['vat'], $line['vatInTaxCurrency']),
                $vatLines,
            ),
        );
    }
}
