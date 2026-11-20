<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use ApiPlatform\Metadata\ApiProperty;
use App\Payroll\Application\Query\GetHourlyRate\HourlyRateView;
use App\Payroll\Domain\Model\HourlyRate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateHourlyRateInputProvider pre-fills it from the current
 * rate and API Platform applies the request body onto it, so a field the client did not send keeps
 * its value.
 */
final class UpdateHourlyRateInput
{
    #[ApiProperty(schema: ['type' => 'string', 'format' => 'date', 'description' => 'The first business date the rate is in effect (YYYY-MM-DD).'])]
    #[Assert\NotBlank]
    #[Assert\Date]
    public ?string $effectiveFrom = null;

    /** Gross minor units per hour in the organization's currency. */
    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: HourlyRate::MAX_AMOUNT)]
    public ?int $amount = null;

    public static function fromView(HourlyRateView $view): self
    {
        $input = new self();
        $input->effectiveFrom = $view->effectiveFrom;
        $input->amount = $view->amount;

        return $input;
    }
}
