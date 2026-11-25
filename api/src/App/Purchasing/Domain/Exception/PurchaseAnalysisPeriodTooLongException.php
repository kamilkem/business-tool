<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

use App\Shared\Domain\Time\BusinessPeriod;

/** The purchase analysis covers at most a set number of days per request. */
final class PurchaseAnalysisPeriodTooLongException extends PurchasingException
{
    public static function of(BusinessPeriod $period, int $maxDays): self
    {
        return new self(\sprintf('The period has %d days; at most %d are allowed.', $period->dayCount(), $maxDays), ['from' => $period->first->toString(), 'to' => $period->last->toString(), 'days' => $period->dayCount(), 'maxDays' => $maxDays]);
    }
}
