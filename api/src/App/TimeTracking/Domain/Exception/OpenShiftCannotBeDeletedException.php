<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/**
 * A running shift is not deleted: that would clock the person out behind their back. The person
 * clocks out first, or an owner or manager gives the shift its end, then it can be deleted (#28).
 */
final class OpenShiftCannotBeDeletedException extends TimeTrackingException
{
    public static function of(string $shiftId): self
    {
        return new self(\sprintf('Shift "%s" is still open; it can be deleted once it has ended.', $shiftId), ['shiftId' => $shiftId]);
    }
}
