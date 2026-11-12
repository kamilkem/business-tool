<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Model;

/** What a shift change request asks for (ShiftChangeRequest). */
enum ShiftChangeRequestKind: string
{
    /** A shift the person worked but did not clock: approving it enters the shift (Shift::record()). */
    case MissingShift = 'missing_shift';
    /** New times for one of the person's shifts: approving it corrects the shift (Shift::correct()). */
    case NewTimes = 'new_times';
}
