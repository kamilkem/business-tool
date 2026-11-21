<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time\Exception;

use App\Shared\Domain\Time\BusinessDate;

/** A period whose last day is before its first, or a month that does not exist. */
final class InvalidBusinessPeriodException extends TimeException
{
    public static function endsBeforeItStarts(BusinessDate $first, BusinessDate $last): self
    {
        return new self(\sprintf('A period cannot end on %s: it starts on %s.', $last, $first), ['from' => $first->toString(), 'to' => $last->toString()], variant: __FUNCTION__);
    }

    public static function notAMonth(int $year, int $month): self
    {
        return new self(\sprintf('%04d-%02d is not a month.', $year, $month), ['value' => \sprintf('%04d-%02d', $year, $month)], variant: __FUNCTION__);
    }

    public static function notAMonthString(string $value): self
    {
        return new self(\sprintf('"%s" is not a month (YYYY-MM).', $value), ['value' => $value], variant: __FUNCTION__);
    }
}
