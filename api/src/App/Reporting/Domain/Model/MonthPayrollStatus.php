<?php

declare(strict_types=1);

namespace App\Reporting\Domain\Model;

/** Where a month's payroll stands, for the summary: no run yet, a draft (an estimate) or approved (the cost; ADR 0029 §4). */
enum MonthPayrollStatus: string
{
    case None = 'none';
    case Draft = 'draft';
    case Approved = 'approved';
}
