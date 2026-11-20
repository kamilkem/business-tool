<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ExportPayrollRun;

final readonly class PaycheckExportView
{
    /** @param list<PaycheckLineExportView> $lines oldest first */
    public function __construct(
        public string $userId,
        /** Empty when the person is no longer known (never expected: members are deactivated, not deleted). */
        public string $firstName,
        public string $lastName,
        public int $paidSeconds,
        /** Minor units. */
        public int $payForHours,
        public int $adjustmentsTotal,
        public int $total,
        /** The business date it was marked paid on (YYYY-MM-DD); null while unpaid. */
        public ?string $paidOn,
        /** 'cash' or 'transfer'; null while unpaid. */
        public ?string $paymentMethod,
        public array $lines,
    ) {
    }
}
