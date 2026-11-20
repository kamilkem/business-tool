<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query;

use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Model\Paycheck;
use App\Payroll\Domain\Model\PayrollRun;
use App\Payroll\Domain\Model\PayrollRunStatus;

/**
 * What of a run a member sees (ADR 0027 §10): owners and managers see every run with every paycheck;
 * an employee sees only approved runs that have a paycheck of theirs, and only that paycheck.
 */
final class PayrollRunVisibility
{
    /** @return list<Paycheck>|null the paychecks to show; null when the run is not visible */
    public static function paychecksFor(PayrollActor $actor, PayrollRun $run): ?array
    {
        if ($actor->role->canManageOrganization()) {
            return $run->paychecks();
        }
        $own = $run->findPaycheck($actor->id);

        return PayrollRunStatus::Approved === $run->getStatus() && null !== $own ? [$own] : null;
    }
}
