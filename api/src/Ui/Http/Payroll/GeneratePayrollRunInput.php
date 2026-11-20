<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use ApiPlatform\Metadata\ApiProperty;
use App\Payroll\Domain\Model\RoundingDirection;
use App\Payroll\Domain\Model\RoundingUnit;
use Symfony\Component\Validator\Constraints as Assert;

/** One run per month (409), a month not after the current one (422) and with something to pay (409) are the domain's. */
final readonly class GeneratePayrollRunInput
{
    public function __construct(
        #[ApiProperty(schema: ['type' => 'string', 'pattern' => '^\d{4}-\d{2}$', 'example' => '2026-10', 'description' => 'The calendar month (YYYY-MM) in the organization\'s time zone.'])]
        #[Assert\NotBlank]
        #[Assert\Regex('/^\d{4}-(0[1-9]|1[0-2])$/', message: 'This is not a month (YYYY-MM).')]
        public string $month,
        /** What each shift's length is rounded to: exact seconds, or 15, 30 or 60 minutes. */
        public RoundingUnit $roundingUnit = RoundingUnit::None,
        /** Which way: nearest (a half rounds up), up or down. Ignored with roundingUnit "none". */
        public RoundingDirection $roundingDirection = RoundingDirection::Nearest,
    ) {
    }
}
