<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

/**
 * The paid mark does not allow the operation (ADR 0027 §9): a paycheck is marked paid once (undo the
 * mark to change it), only a paid one can be unmarked, and a run with a paid paycheck is not reopened.
 */
final class PaycheckPaymentException extends PayrollException
{
    public static function alreadyPaid(string $paycheckId): self
    {
        return new self(\sprintf('Paycheck "%s" is marked paid already: undo the mark first to change it.', $paycheckId), ['paycheckId' => $paycheckId], variant: __FUNCTION__);
    }

    public static function notPaid(string $paycheckId): self
    {
        return new self(\sprintf('Paycheck "%s" is not marked paid.', $paycheckId), ['paycheckId' => $paycheckId], variant: __FUNCTION__);
    }

    public static function runHasPayments(string $runId, int $paid): self
    {
        return new self(\sprintf('Payroll run "%s" has %d paycheck(s) marked paid: undo the marks first, or fix a mistake with an adjustment in a later month.', $runId, $paid), ['runId' => $runId, 'count' => $paid], variant: __FUNCTION__);
    }
}
