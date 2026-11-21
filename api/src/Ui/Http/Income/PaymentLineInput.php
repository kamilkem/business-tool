<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Domain\Model\RevenueEntry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The gross amount taken with one of the organization's payment methods, in integer minor units of
 * the organization's currency. Whether the method is the organization's and active is the domain's.
 * Properties, not constructor parameters: the serializer then skips a value of the wrong type (a
 * fraction, a string) and reports it as a 422, instead of passing it to a constructor that PHP
 * would coerce it for.
 */
final class PaymentLineInput
{
    /** A payment method of the organization (GET /payment-methods). */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $paymentMethodId;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(RevenueEntry::MAX_AMOUNT)]
    public int $amount;

    public static function of(string $paymentMethodId, int $amount): self
    {
        $line = new self();
        $line->paymentMethodId = $paymentMethodId;
        $line->amount = $amount;

        return $line;
    }

    /** For Assert\Unique: lines are the same when their methods are. */
    public static function methodOf(mixed $line): mixed
    {
        return $line instanceof self && isset($line->paymentMethodId) ? strtolower($line->paymentMethodId) : $line;
    }
}
