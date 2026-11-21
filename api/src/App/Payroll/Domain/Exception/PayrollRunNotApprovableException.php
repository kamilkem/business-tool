<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use App\Shared\Domain\Time\BusinessPeriod;

/** A draft that may not be approved yet (ADR 0027 §7); the message names the reason. */
final class PayrollRunNotApprovableException extends PayrollException
{
    public static function monthNotEnded(BusinessPeriod $month): self
    {
        return new self(\sprintf('The month %s has not ended yet: its draft is a preview.', substr($month->first->toString(), 0, 7)), ['month' => substr($month->first->toString(), 0, 7)], variant: __FUNCTION__);
    }

    public static function openShifts(int $count): self
    {
        return new self(\sprintf('%d shift(s) of the month are still open: end them first.', $count), ['count' => $count], variant: __FUNCTION__);
    }

    public static function pendingChangeRequests(int $count): self
    {
        return new self(\sprintf('%d shift change request(s) about the month are pending: decide them first.', $count), ['count' => $count], variant: __FUNCTION__);
    }

    public static function nothingToPay(): self
    {
        return new self('The run has no paychecks.', [], variant: __FUNCTION__);
    }

    public static function linesWithoutRate(int $count): self
    {
        return new self(\sprintf('%d shift line(s) have no rate: set the rates, then regenerate the run.', $count), ['count' => $count], variant: __FUNCTION__);
    }

    public static function negativeTotal(string $userId): self
    {
        return new self(\sprintf('The paycheck of user "%s" has a negative total: move part of the deduction to a later month.', $userId), ['userId' => $userId], variant: __FUNCTION__);
    }

    public static function outOfDate(): self
    {
        return new self('Shifts, rates or the currency changed since the run was generated: regenerate it and review it again.', [], variant: __FUNCTION__);
    }
}
