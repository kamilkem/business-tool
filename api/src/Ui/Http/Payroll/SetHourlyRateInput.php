<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use ApiPlatform\Metadata\ApiProperty;
use App\Payroll\Domain\Model\HourlyRate;
use Symfony\Component\Validator\Constraints as Assert;

/** One rate per member and date (409) and the locked periods (409) are the domain's. */
final readonly class SetHourlyRateInput
{
    public function __construct(
        /** The member, active or deactivated. */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $userId,
        #[ApiProperty(schema: ['type' => 'string', 'format' => 'date', 'description' => 'The first business date the rate is in effect (YYYY-MM-DD).'])]
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $effectiveFrom,
        /** Gross minor units per hour in the organization's currency (e.g. 3050 for 30.50). */
        #[Assert\Range(min: 1, max: HourlyRate::MAX_AMOUNT)]
        public int $amount,
    ) {
    }
}
