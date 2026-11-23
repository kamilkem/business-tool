<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** The maximum shift length is a whole number of hours within MaxShiftLength's range (#33). */
final class InvalidMaxShiftLengthException extends TimeTrackingException
{
    public static function of(int $hours, int $min, int $max): self
    {
        return new self(\sprintf('The maximum shift length must be from %d to %d hours, %d given.', $min, $max, $hours), ['maxShiftHours' => $hours, 'min' => $min, 'max' => $max]);
    }
}
