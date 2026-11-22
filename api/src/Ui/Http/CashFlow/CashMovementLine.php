<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Application\Query\GetCashMovement\CashMovementLineView;

/** Pieces of one denomination in a movement; amounts in minor units of the movement's currency. */
final readonly class CashMovementLine
{
    public function __construct(
        public string $denominationId,
        /** Face value in minor units. */
        public int $value,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['coin', 'note']])]
        public string $kind,
        /** Signed for an adjustment, moved for a transfer, counted for a count. */
        public int $pieces,
        /** pieces × value. */
        public int $amount,
    ) {
    }

    public static function fromView(CashMovementLineView $view): self
    {
        return new self($view->denominationId, $view->value, $view->kind, $view->pieces, $view->amount);
    }
}
