<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListPurchaseInvoices;

use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceVatLineView;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Income\Domain\Model\VatRates;

/**
 * The invoices of one currency added up, in its minor units (ADR 0010). Corrections count with their
 * own (possibly negative) amounts. As for one invoice, the totals need not match the lines.
 */
final readonly class PurchaseInvoiceCurrencyTotalView
{
    /** @param list<PurchaseInvoiceVatLineView> $vatLines per VAT rate used, highest rate first, exempt last */
    public function __construct(
        /** ISO 4217 code. */
        public string $currency,
        public int $invoiceCount,
        public int $netTotal,
        public int $vatTotal,
        public int $grossTotal,
        public array $vatLines,
    ) {
    }

    /** @param list<PurchaseInvoice> $invoices invoices in $currency */
    public static function of(string $currency, array $invoices): self
    {
        /** @var array<string, array{int, int, ?int}> $byRate net, VAT, VAT in the tax currency */
        $byRate = [];
        foreach ($invoices as $invoice) {
            foreach ($invoice->getVatLines() as $line) {
                [$net, $vat, $vatInTaxCurrency] = $byRate[$line->rate] ?? [0, 0, null];
                $byRate[$line->rate] = [
                    $net + $line->net,
                    $vat + $line->vat,
                    null === $line->vatInTaxCurrency ? $vatInTaxCurrency : ($vatInTaxCurrency ?? 0) + $line->vatInTaxCurrency,
                ];
            }
        }

        $rates = array_map(strval(...), array_keys($byRate));
        usort($rates, VatRates::compare(...));

        return new self(
            $currency,
            \count($invoices),
            array_sum(array_map(static fn (PurchaseInvoice $invoice): int => $invoice->getNetTotal(), $invoices)),
            array_sum(array_map(static fn (PurchaseInvoice $invoice): int => $invoice->getVatTotal(), $invoices)),
            array_sum(array_map(static fn (PurchaseInvoice $invoice): int => $invoice->getGrossTotal(), $invoices)),
            array_map(static fn (string $rate): PurchaseInvoiceVatLineView => new PurchaseInvoiceVatLineView($rate, ...$byRate[$rate]), $rates),
        );
    }
}
