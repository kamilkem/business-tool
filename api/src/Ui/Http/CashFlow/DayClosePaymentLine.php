<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use App\CashFlow\Application\Query\GetDayClose\DayClosePaymentView;

/** The day's amount of one non-cash payment method at a close. */
final readonly class DayClosePaymentLine
{
    public function __construct(
        public string $paymentMethodId,
        /** The method's current name; null when it is no longer offered. */
        public ?string $name,
        /** Minor units. */
        public int $amount,
    ) {
    }

    public static function fromView(DayClosePaymentView $view): self
    {
        return new self($view->paymentMethodId, $view->name, $view->amount);
    }
}
