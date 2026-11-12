<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Model;

/** A request waits for an owner or manager, who approves or rejects it once; a decision is final. */
enum ShiftChangeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
