<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use App\Shared\Domain\Time\BusinessPeriod;

/**
 * A run is generated or regenerated only for a month with something to pay: an ended shift or an
 * adjustment (decision of the product owner on #62).
 */
final class PayrollRunEmptyException extends PayrollException
{
    public static function month(BusinessPeriod $month): self
    {
        return new self(\sprintf('Nothing to pay for %s: there are no ended shifts and no adjustments.', substr($month->first->toString(), 0, 7)));
    }
}
