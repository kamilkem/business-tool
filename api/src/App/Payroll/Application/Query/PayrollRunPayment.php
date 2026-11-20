<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query;

use App\Payroll\Domain\Model\Paycheck;
use App\Payroll\Domain\Model\PayrollRun;
use App\Payroll\Domain\Model\PayrollRunStatus;

/**
 * Whether a run is paid, over the paychecks the actor sees (PayrollRunVisibility): for owners and
 * managers that is every paycheck, as PayrollRun::isPaid(); for an employee, their own. Derived,
 * not stored (ADR 0027 §9).
 */
final class PayrollRunPayment
{
    /** @param list<Paycheck> $paychecks the ones shown */
    public static function paidPaychecks(array $paychecks): int
    {
        return \count(array_filter($paychecks, static fn (Paycheck $paycheck): bool => $paycheck->isPaid()));
    }

    /** @param list<Paycheck> $paychecks the ones shown */
    public static function isPaid(PayrollRun $run, array $paychecks): bool
    {
        return PayrollRunStatus::Approved === $run->getStatus()
            && [] !== $paychecks
            && self::paidPaychecks($paychecks) === \count($paychecks);
    }
}
