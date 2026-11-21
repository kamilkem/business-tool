<?php

declare(strict_types=1);

namespace Ui\Http\Income;

/** The input lines as the primitive arrays the commands carry. */
final class RevenueEntryLines
{
    /**
     * @param list<PaymentLineInput> $lines
     *
     * @return list<array{paymentMethodId: string, amount: int}>
     */
    public static function payments(array $lines): array
    {
        return array_map(static fn (PaymentLineInput $line): array => ['paymentMethodId' => $line->paymentMethodId, 'amount' => $line->amount], $lines);
    }

    /**
     * @param list<VatLineInput> $lines
     *
     * @return list<array{rate: string, gross: int, vat: int}>
     */
    public static function vat(array $lines): array
    {
        return array_map(static fn (VatLineInput $line): array => ['rate' => $line->rate, 'gross' => $line->gross, 'vat' => $line->vat], $lines);
    }
}
