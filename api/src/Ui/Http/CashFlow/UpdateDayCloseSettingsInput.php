<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Domain\Model\DayCloseDateMode;
use Symfony\Component\Validator\Constraints as Assert;

/** The whole setting: both fields are required (PUT). */
final readonly class UpdateDayCloseSettingsInput
{
    /** HH:MM from 00:00 to 12:00 (DayEndTime::LATEST). */
    public const string DAY_END_TIME = '/^((0\d|1[01]):[0-5]\d|12:00)$/D';

    public function __construct(
        /** `day_end_time`: the date follows from the day-end time; `employee_picks`: the employee picks today or yesterday. */
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['day_end_time', 'employee_picks']])]
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [self::class, 'modes'])]
        public string $mode,
        /** HH:MM in the organization's time zone, from 00:00 to 12:00: a close before it belongs to the previous date. */
        #[ApiProperty(schema: ['type' => 'string', 'pattern' => '^\d{2}:\d{2}$', 'example' => '05:00'])]
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::DAY_END_TIME, message: 'This is not a time from 00:00 to 12:00 (HH:MM).')]
        public string $dayEndsAt,
    ) {
    }

    /** @return list<string> */
    public static function modes(): array
    {
        return array_map(static fn (DayCloseDateMode $mode): string => $mode->value, DayCloseDateMode::cases());
    }
}
