<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use App\CashFlow\Application\Query\GetDayCloseForm\DayCloseFormClosedView;

/** Who closed the proposed date, and when. */
final readonly class DayCloseFormClosed
{
    public function __construct(
        public string $dayCloseId,
        public string $closedBy,
        public string $closedByFirstName,
        public string $closedByLastName,
        public \DateTimeImmutable $closedAt,
    ) {
    }

    public static function fromView(DayCloseFormClosedView $view): self
    {
        return new self($view->dayCloseId, $view->closedById, $view->closedByFirstName, $view->closedByLastName, $view->closedAt);
    }
}
