<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use App\Expenses\Domain\Model\PurchaseInvoiceKind;

/** Limits, choices and conversions the expense inputs share. */
final class ExpenseInputRules
{
    /**
     * The largest amount, either sign, in minor units: 2^53 - 1 (JavaScript's Number.MAX_SAFE_INTEGER),
     * so a client never loses precision. Far above any real expense, and so no limit on sending an
     * imported expense's own amounts back.
     */
    public const int MAX = 9_007_199_254_740_991;

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_map(static fn (PurchaseInvoiceKind $kind): string => $kind->value, PurchaseInvoiceKind::cases());
    }

    /**
     * @param list<ExpenseVatLineInput> $lines
     *
     * @return list<array{rate: string, net: int, vat: int, vatInTaxCurrency: ?int}>
     */
    public static function vatLines(array $lines): array
    {
        return array_map(static fn (ExpenseVatLineInput $line): array => ['rate' => $line->rate, 'net' => $line->net, 'vat' => $line->vat, 'vatInTaxCurrency' => $line->vatInTaxCurrency], $lines);
    }
}
