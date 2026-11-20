<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use App\Payroll\Domain\Model\RoundingDirection;
use App\Payroll\Domain\Model\RoundingUnit;
use Symfony\Component\Validator\Constraints as Assert;

/** New rounding for the run: both fields, or neither to keep the run's. */
final readonly class RegeneratePayrollRunInput
{
    public function __construct(
        public ?RoundingUnit $roundingUnit = null,
        public ?RoundingDirection $roundingDirection = null,
    ) {
    }

    #[Assert\IsTrue(message: 'Give both roundingUnit and roundingDirection, or neither.')]
    public function isRoundingComplete(): bool
    {
        return (null === $this->roundingUnit) === (null === $this->roundingDirection);
    }
}
