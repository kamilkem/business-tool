<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use App\Payroll\Application\Query\GetPayrollRun\PaycheckAdjustmentView;
use App\Payroll\Domain\Model\PaycheckAdjustment;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdatePaycheckAdjustmentInputProvider pre-fills it from the
 * current adjustment and API Platform applies the request body onto it.
 */
final class UpdatePaycheckAdjustmentInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: PaycheckAdjustment::MAX_LABEL_LENGTH)]
    public ?string $label = null;

    /** Signed minor units in the run's currency; not 0. */
    #[Assert\NotNull]
    #[Assert\NotEqualTo(0)]
    #[Assert\Range(min: -PaycheckAdjustment::MAX_AMOUNT, max: PaycheckAdjustment::MAX_AMOUNT)]
    public ?int $amount = null;

    public static function fromView(PaycheckAdjustmentView $view): self
    {
        $input = new self();
        $input->label = $view->label;
        $input->amount = $view->amount;

        return $input;
    }
}
