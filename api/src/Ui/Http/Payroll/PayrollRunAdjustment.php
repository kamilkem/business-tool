<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

/** A bonus (positive) or a deduction such as an advance (negative) on a paycheck. */
final readonly class PayrollRunAdjustment
{
    public function __construct(
        public string $id,
        public string $label,
        /** Signed minor units in the run's currency. */
        public int $amount,
        public \DateTimeImmutable $createdAt,
        /** When it was last changed; null until then. */
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }
}
