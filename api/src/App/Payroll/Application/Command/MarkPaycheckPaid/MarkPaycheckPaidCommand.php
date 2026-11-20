<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\MarkPaycheckPaid;

use App\Shared\Application\Command\CommandInterface;

/** An owner marks a paycheck of an approved payroll run paid (ADR 0027 §9). */
final readonly class MarkPaycheckPaidCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
        public string $paycheckId,
        /** The business date it was paid on (YYYY-MM-DD), today or earlier in the organization's time zone. */
        public string $paidOn,
        /** A PaymentMethod value: 'cash' or 'transfer'. */
        public string $method,
    ) {
    }
}
