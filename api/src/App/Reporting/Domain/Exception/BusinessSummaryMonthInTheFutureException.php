<?php

declare(strict_types=1);

namespace App\Reporting\Domain\Exception;

use App\Shared\Domain\Time\BusinessPeriod;

/** The summary covers past months and the current one as a preview, never a later one (ADR 0029 §2). */
final class BusinessSummaryMonthInTheFutureException extends ReportingException
{
    public static function month(BusinessPeriod $month): self
    {
        $value = substr($month->first->toString(), 0, 7);

        return new self(\sprintf('The month %s has not started yet.', $value), ['month' => $value]);
    }
}
