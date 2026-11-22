<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Application\Query\GetDayClose\DayCloseStorageView;

/** What the person closing entered for one storage, and its total: no balance (ADR 0033 §6). */
final readonly class DayCloseReceiptStorage
{
    /** @param list<DayClosePiecesLine> $lines by value, lowest first */
    public function __construct(
        public string $storageId,
        public string $name,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['counted', 'deposit']])]
        public string $mode,
        /** Minor units: what was counted in a counted storage, or put into a deposit one. */
        public int $total,
        public array $lines,
    ) {
    }

    public static function fromView(DayCloseStorageView $view): self
    {
        return new self($view->storageId, $view->name, $view->mode, $view->total, array_map(DayClosePiecesLine::fromView(...), $view->lines));
    }
}
