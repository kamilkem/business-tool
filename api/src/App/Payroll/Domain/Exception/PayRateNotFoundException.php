<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

/** The rate does not exist in the current organization. */
final class PayRateNotFoundException extends PayrollException
{
    public static function hourlyRate(string $id): self
    {
        return new self(\sprintf('Hourly rate "%s" not found.', $id));
    }

    public static function dayRate(string $id): self
    {
        return new self(\sprintf('Day rate "%s" not found.', $id));
    }
}
