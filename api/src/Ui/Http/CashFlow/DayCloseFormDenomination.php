<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Application\Query\GetDayCloseForm\DayCloseFormOptionView;

/** An active denomination of the organization's currency, counted at the close. */
final readonly class DayCloseFormDenomination
{
    public function __construct(
        public string $id,
        /** Face value in minor units. */
        public int $value,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['coin', 'note']])]
        public string $kind,
    ) {
    }

    public static function fromView(DayCloseFormOptionView $view): self
    {
        return new self($view->id, $view->value ?? throw new \LogicException('A denomination has a value.'), $view->kind ?? throw new \LogicException('A denomination has a kind.'));
    }
}
