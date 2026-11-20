<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ListPayRates;

use App\Shared\Application\Query\QueryInterface;

/**
 * The rate of each member of the current organization (TenantFilter), or of one with $userId, on each
 * business date from $from to $to (both inclusive, YYYY-MM-DD): as GetPayRateQuery, one per member and
 * date that has a rate, by date then member. Deactivated members' rates are included (ADR 0014). For
 * a payroll run's month (#62).
 *
 * For Application code, not for an API response: no actor; the consumer checks its own permissions
 * before asking.
 */
final readonly class ListPayRatesQuery implements QueryInterface
{
    /** The longest period one query may cover, in days. */
    public const int MAX_DAYS = 366;

    public function __construct(
        public string $from,
        public string $to,
        public ?string $userId = null,
    ) {
    }
}
