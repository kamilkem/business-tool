<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use App\Shared\Domain\Time\BusinessDate;

/** A person has at most one hourly rate and one day rate per business date (ADR 0027 §3). */
final class PayRateDateTakenException extends PayrollException
{
    public static function hourlyRate(string $userId, BusinessDate $date): self
    {
        return new self(\sprintf('User "%s" already has an hourly rate effective from %s.', $userId, $date));
    }

    public static function dayRate(string $userId, BusinessDate $date): self
    {
        return new self(\sprintf('User "%s" already has a day rate on %s.', $userId, $date));
    }
}
