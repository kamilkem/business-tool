<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use App\Income\Domain\Model\RevenueEntry;
use Symfony\Component\Validator\Constraints as Assert;

/** The day's amount of one active non-cash payment method, in minor units. */
final class DayClosePaymentInput
{
    /** An active non-cash payment method of the organization (GET /day-close-form). */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $paymentMethodId;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(RevenueEntry::MAX_AMOUNT)]
    public int $amount;

    public static function of(string $paymentMethodId, int $amount): self
    {
        $input = new self();
        $input->paymentMethodId = $paymentMethodId;
        $input->amount = $amount;

        return $input;
    }

    /** For Assert\Unique: payments are the same when their methods are. */
    public static function methodOf(mixed $payment): mixed
    {
        return $payment instanceof self && isset($payment->paymentMethodId) ? strtolower($payment->paymentMethodId) : $payment;
    }
}
