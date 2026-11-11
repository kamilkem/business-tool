<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time\Exception;

use App\Shared\Domain\Time\BusinessDate;

/** A period whose last day is before its first, or a month that does not exist. */
final class InvalidBusinessPeriodException extends TimeException
{
    public static function endsBeforeItStarts(BusinessDate $first, BusinessDate $last): self
    {
        return new self(\sprintf('A period cannot end on %s: it starts on %s.', $last, $first));
    }

    public static function notAMonth(int $year, int $month): self
    {
        return new self(\sprintf('%04d-%02d is not a month.', $year, $month));
    }
}
