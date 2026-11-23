<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use ApiPlatform\Metadata\ApiProperty;
use App\TimeTracking\Domain\Model\MaxShiftLength;
use Symfony\Component\Validator\Constraints as Assert;

/** The whole setting: every field is required (PUT). */
final readonly class UpdateTimeTrackingSettingsInput
{
    public function __construct(
        /** Whole hours a shift may run before it is flagged as a possibly forgotten clock-out. */
        #[ApiProperty(schema: ['type' => 'integer', 'minimum' => MaxShiftLength::MIN_HOURS, 'maximum' => MaxShiftLength::MAX_HOURS, 'example' => MaxShiftLength::DEFAULT_HOURS])]
        #[Assert\Range(min: MaxShiftLength::MIN_HOURS, max: MaxShiftLength::MAX_HOURS)]
        public int $maxShiftHours,
    ) {
    }
}
