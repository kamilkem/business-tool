<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Application\Query\GetRevenueMonth\RevenueMonthActionView;

/** One close or reopen of a month by an owner. */
final readonly class RevenueMonthAction
{
    public function __construct(
        /** `closed` or `reopened`. */
        public string $action,
        /** The owner, a user id (GET /members). */
        public string $actorId,
        public \DateTimeImmutable $at,
    ) {
    }

    public static function fromView(RevenueMonthActionView $view): self
    {
        return new self($view->action, $view->actorId, $view->at);
    }
}
