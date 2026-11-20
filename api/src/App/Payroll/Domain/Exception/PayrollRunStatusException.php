<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

/** The operation needs the run in another status: a draft to change or approve, an approved run to reopen. */
final class PayrollRunStatusException extends PayrollException
{
    public static function notDraft(string $id): self
    {
        return new self(\sprintf('Payroll run "%s" is approved: reopen it first.', $id));
    }

    public static function notApproved(string $id): self
    {
        return new self(\sprintf('Payroll run "%s" is a draft, not an approved run.', $id));
    }
}
