<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Shared\Domain\Time\BusinessDate;
use Symfony\Component\Uid\Uuid;

/**
 * A shift as Time tracking reports it for a payroll month (ADR 0027 §5): it belongs wholly to the
 * business date it started on and is never split at midnight or at the end of the month.
 */
final readonly class WorkedShift
{
    public function __construct(
        public Uuid $shiftId,
        public Uuid $userId,
        public BusinessDate $date,
        /** The real length, unrounded; null while the shift is open. */
        public ?int $seconds,
    ) {
    }

    public function isOpen(): bool
    {
        return null === $this->seconds;
    }
}
