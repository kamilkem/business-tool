<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Model;

/**
 * How a shift came to be. Its times may have been corrected since (Shift::getCorrectedBy()); the
 * source does not change.
 */
enum ShiftSource: string
{
    /** The person clocked in themselves, at the server's time (ADR 0016). */
    case Clock = 'clock';

    /** An owner or manager entered it for the person after the fact (Shift::record()). */
    case Manager = 'manager';
}
