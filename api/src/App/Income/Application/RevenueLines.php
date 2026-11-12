<?php

declare(strict_types=1);

namespace App\Income\Application;

use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\VatAmount;

/** Turns the primitive lines of a command into the domain's value objects. */
final class RevenueLines
{
    /**
     * @param list<array{method: string, amount: int}> $payments
     *
     * @return list<PaymentAmount>
     *
     * @throws InvalidPaymentLinesException when a method is not a PaymentMethod
     */
    public static function payments(array $payments): array
    {
        return array_map(
            static fn (array $line): PaymentAmount => new PaymentAmount(
                PaymentMethod::tryFrom($line['method']) ?? throw InvalidPaymentLinesException::unknownMethod($line['method']),
                $line['amount'],
            ),
            $payments,
        );
    }

    /**
     * @param list<array{rate: string, gross: int, vat: int}> $vatLines
     *
     * @return list<VatAmount>
     */
    public static function vat(array $vatLines): array
    {
        return array_map(static fn (array $line): VatAmount => new VatAmount($line['rate'], $line['gross'], $line['vat']), $vatLines);
    }
}
