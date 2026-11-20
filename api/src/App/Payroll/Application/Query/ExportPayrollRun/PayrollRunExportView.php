<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ExportPayrollRun;

/** An approved run for the accountant, with the organization's settings that shape the file. */
final readonly class PayrollRunExportView
{
    /** @param list<PaycheckExportView> $paychecks by last name, first name */
    public function __construct(
        public string $id,
        /** YYYY-MM */
        public string $month,
        public string $firstDate,
        public string $lastDate,
        /** 'approved' (a draft is not exported). */
        public string $status,
        public string $currency,
        /** The organization's time zone, for the shifts' start and end. */
        public string $timeZone,
        /** The organization's CsvSeparator value. */
        public string $csvSeparator,
        public array $paychecks,
    ) {
    }
}
