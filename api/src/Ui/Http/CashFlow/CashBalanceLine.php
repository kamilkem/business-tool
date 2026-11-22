<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Application\Query\ListCashBalances\CashBalanceLineView;

/** What a storage holds of one denomination. */
final readonly class CashBalanceLine
{
    public function __construct(
        public string $denominationId,
        /** ISO 4217 */
        public string $currency,
        /** Face value in minor units. */
        public int $value,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['coin', 'note']])]
        public string $kind,
        /** False when the denomination is deactivated: take its pieces out with an adjustment. */
        public bool $active,
        /** May be below zero in a counted storage, whose balance between closes is an estimate. */
        public int $pieces,
        /** pieces × value, in minor units. */
        public int $amount,
    ) {
    }

    public static function fromView(CashBalanceLineView $view): self
    {
        return new self($view->denominationId, $view->currency, $view->value, $view->kind, $view->active, $view->pieces, $view->amount);
    }
}
