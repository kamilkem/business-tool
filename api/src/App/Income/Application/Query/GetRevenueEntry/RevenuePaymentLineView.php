<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueEntry;

use App\Income\Domain\Model\PaymentAmount;

final readonly class RevenuePaymentLineView
{
    public function __construct(
        public string $paymentMethodId,
        /** The method's current name. */
        public string $paymentMethodName,
        public int $amount,
    ) {
    }

    public static function of(PaymentAmount $line): self
    {
        return new self($line->method->getId()->toRfc4122(), $line->method->getName(), $line->amount);
    }
}
