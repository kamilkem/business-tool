<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/**
 * An ended shift keeps an end time: removing it would clock the person in on their behalf, which
 * nobody does (ADR 0016 §1).
 */
final class ShiftCannotBeReopenedException extends TimeTrackingException
{
    public static function of(string $shiftId): self
    {
        return new self(\sprintf('Shift "%s" has ended; its end time can be changed but not removed.', $shiftId));
    }
}
