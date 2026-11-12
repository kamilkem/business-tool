<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** A request explains the change to whoever decides it: its note is required. */
final class ShiftChangeRequestNoteRequiredException extends TimeTrackingException
{
    public static function create(): self
    {
        return new self('A shift change request needs a note explaining the change.');
    }
}
