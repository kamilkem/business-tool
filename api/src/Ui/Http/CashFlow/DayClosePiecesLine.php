<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Application\Query\GetDayClose\DayCloseLineView;

/** Pieces of one denomination in one storage at a close; amounts in minor units of the close's currency. */
final readonly class DayClosePiecesLine
{
    public function __construct(
        public string $denominationId,
        /** Face value in minor units. */
        public int $value,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['coin', 'note']])]
        public string $kind,
        /** What stays in a counted storage, or what was put into a deposit one. */
        public int $pieces,
        /** pieces × value. */
        public int $amount,
    ) {
    }

    public static function fromView(DayCloseLineView $view): self
    {
        return new self($view->denominationId, $view->value, $view->kind, $view->pieces, $view->amount);
    }
}
