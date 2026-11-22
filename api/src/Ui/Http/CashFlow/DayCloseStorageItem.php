<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Application\Query\GetDayClose\DayCloseStorageView;

/** What a close did to one storage, with its expected opening: for owners and managers. */
final readonly class DayCloseStorageItem
{
    /** @param list<DayClosePiecesLine> $lines by value, lowest first */
    public function __construct(
        public string $storageId,
        /** The storage's current name. */
        public string $name,
        /** The storage's mode at the close. */
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['counted', 'deposit']])]
        public string $mode,
        /** Minor units: what was counted in a counted storage, or put into a deposit one. */
        public int $total,
        /** Minor units: a counted storage's balance just before the close; null for a deposit storage. */
        public ?int $expectedOpening,
        /** Minor units: what the storage adds to the cash income (total − expected opening, or the deposit). */
        public int $cashIncome,
        public array $lines,
    ) {
    }

    public static function fromView(DayCloseStorageView $view): self
    {
        return new self(
            $view->storageId,
            $view->name,
            $view->mode,
            $view->total,
            $view->expectedOpening,
            $view->cashIncome ?? throw new \LogicException('A close\'s details have the cash income per storage.'),
            array_map(DayClosePiecesLine::fromView(...), $view->lines),
        );
    }
}
