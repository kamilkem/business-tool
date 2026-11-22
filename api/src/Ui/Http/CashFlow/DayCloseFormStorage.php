<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Application\Query\GetDayCloseForm\DayCloseFormOptionView;

/** An active storage the close covers: counted in full, or the pieces put into it. */
final readonly class DayCloseFormStorage
{
    public function __construct(
        public string $id,
        public string $name,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['counted', 'deposit']])]
        public string $mode,
    ) {
    }

    public static function fromView(DayCloseFormOptionView $view): self
    {
        return new self($view->id, $view->name ?? throw new \LogicException('A storage has a name.'), $view->kind ?? throw new \LogicException('A storage has a mode.'));
    }
}
