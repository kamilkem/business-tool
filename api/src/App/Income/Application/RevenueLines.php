<?php

declare(strict_types=1);

namespace App\Income\Application;

use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\VatAmount;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Turns the primitive lines of a command into the domain's value objects. */
final class RevenueLines
{
    /**
     * @param list<array{paymentMethodId: string, amount: int}> $payments
     *
     * @return list<PaymentAmount>
     *
     * @throws InvalidPaymentLinesException when a method is not one of the organization's
     */
    public static function payments(array $payments, PaymentMethodRepositoryInterface $methods): array
    {
        return array_map(
            static fn (array $line): PaymentAmount => new PaymentAmount(
                (Uuid::isValid($line['paymentMethodId']) ? $methods->findById(Uuid::fromString($line['paymentMethodId'])) : null)
                    ?? throw InvalidPaymentLinesException::unknownMethod($line['paymentMethodId']),
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
