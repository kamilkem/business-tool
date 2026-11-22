<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use App\CashFlow\Application\Query\GetDayCloseForm\DayCloseFormOptionView;

/** A register or a non-cash payment method the close form offers. */
final readonly class DayCloseFormOption
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }

    public static function fromView(DayCloseFormOptionView $view): self
    {
        return new self($view->id, $view->name ?? throw new \LogicException('A register or payment method has a name.'));
    }
}
