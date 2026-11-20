<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use ApiPlatform\Metadata\ApiProperty;
use App\Payroll\Application\Query\GetDayRate\DayRateView;
use App\Payroll\Domain\Model\HourlyRate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateDayRateInputProvider pre-fills it from the current day
 * rate and API Platform applies the request body onto it.
 */
final class UpdateDayRateInput
{
    #[ApiProperty(schema: ['type' => 'string', 'format' => 'date', 'description' => 'The business date whose shifts the rate pays (YYYY-MM-DD).'])]
    #[Assert\NotBlank]
    #[Assert\Date]
    public ?string $date = null;

    /** Gross minor units per hour in the organization's currency. */
    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: HourlyRate::MAX_AMOUNT)]
    public ?int $amount = null;

    public static function fromView(DayRateView $view): self
    {
        $input = new self();
        $input->date = $view->date;
        $input->amount = $view->amount;

        return $input;
    }
}
