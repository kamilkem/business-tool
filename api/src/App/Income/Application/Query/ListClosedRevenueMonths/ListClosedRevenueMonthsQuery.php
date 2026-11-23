<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListClosedRevenueMonths;

use App\Shared\Application\Query\QueryInterface;

/**
 * The closed Income months of the current organization (TenantFilter), as YYYY-MM, without an actor
 * check (ADR 0034 §3): Cash flow has checked its own actor, and asks before it writes anything that
 * Income would book in a closed month. Never sent from Ui.
 */
final readonly class ListClosedRevenueMonthsQuery implements QueryInterface
{
}
