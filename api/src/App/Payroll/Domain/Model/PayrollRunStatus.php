<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

/** A run is a draft until an owner approves it; "paid" is derived from its paychecks (ADR 0027 §9, #63). */
enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
}
