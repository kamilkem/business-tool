<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetPayRate;

use App\Shared\Application\Query\QueryInterface;

/**
 * The rate of a member of the current organization (TenantFilter) on a business date (YYYY-MM-DD):
 * their day rate for it if there is one, else the hourly rate in effect on it; null when they have
 * none (ADR 0027 §3). For payroll runs (#62) and the employee's estimate (#66).
 *
 * For Application code, not for an API response: no actor; the consumer checks its own permissions
 * before asking.
 */
final readonly class GetPayRateQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
        public string $date,
    ) {
    }
}
