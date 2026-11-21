<?php

declare(strict_types=1);

namespace App\Reporting\Domain\Exception;

/** A summary was asked for over more months than one request may cover (ADR 0029 §2). */
final class BusinessSummaryPeriodTooLongException extends ReportingException
{
    public static function of(string $from, string $to, int $months, int $maxMonths): self
    {
        return new self(
            \sprintf('The business summary covers at most %d months at a time; %s to %s is %d months.', $maxMonths, $from, $to, $months),
            ['from' => $from, 'to' => $to, 'months' => $months, 'maxMonths' => $maxMonths],
        );
    }
}
