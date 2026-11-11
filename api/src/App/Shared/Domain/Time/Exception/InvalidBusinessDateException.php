<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time\Exception;

/** A value that is not a calendar date, or not written as Y-m-d. */
final class InvalidBusinessDateException extends TimeException
{
    public static function notYmd(string $value): self
    {
        return new self(\sprintf('"%s" is not a date in the YYYY-MM-DD format.', $value));
    }

    public static function notACalendarDate(int $year, int $month, int $day): self
    {
        return new self(\sprintf('%04d-%02d-%02d is not a calendar date.', $year, $month, $day));
    }
}
