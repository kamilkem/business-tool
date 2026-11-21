<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Application\Query\GetRevenueEntry\RevenuePaymentLineView;

/** The gross amount taken with one of the organization's payment methods, in minor units. */
final readonly class RevenueEntryPayment
{
    public function __construct(
        public string $paymentMethodId,
        /** The method's current name. */
        public string $paymentMethodName,
        public int $amount,
    ) {
    }

    public static function fromView(RevenuePaymentLineView $view): self
    {
        return new self($view->paymentMethodId, $view->paymentMethodName, $view->amount);
    }
}
