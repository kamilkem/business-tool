<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use App\Payroll\Domain\Model\PaycheckAdjustment;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddPaycheckAdjustmentInput
{
    public function __construct(
        /** The member, active or deactivated; a paycheck is created for one with no shifts that month. */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $userId,
        /** What it is for, e.g. "Bonus" or "Advance paid 10 October". */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: PaycheckAdjustment::MAX_LABEL_LENGTH)]
        public string $label,
        /** Signed minor units in the run's currency: positive adds (a bonus), negative takes off (an advance). Not 0. */
        #[Assert\NotEqualTo(0)]
        #[Assert\Range(min: -PaycheckAdjustment::MAX_AMOUNT, max: PaycheckAdjustment::MAX_AMOUNT)]
        public int $amount,
    ) {
    }
}
