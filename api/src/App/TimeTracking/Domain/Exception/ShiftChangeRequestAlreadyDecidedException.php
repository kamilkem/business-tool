<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** A request is approved or rejected once; the decision is final. */
final class ShiftChangeRequestAlreadyDecidedException extends TimeTrackingException
{
    public static function of(string $requestId, string $status): self
    {
        return new self(\sprintf('Shift change request "%s" has already been decided: it is %s.', $requestId, $status), ['requestId' => $requestId, 'status' => $status]);
    }
}
