<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListDayCloses;

use App\Shared\Application\Query\QueryInterface;

/**
 * The end-of-day closes of the current organization for the business dates from $from to $to
 * (Y-m-d, inclusive), latest first, for an owner or manager (ADR 0033 §6). Returns null when the
 * actor is not an active member; throws when the actor is an employee.
 */
final readonly class ListDayClosesQuery implements QueryInterface
{
    /** The longest period one request covers: about a quarter. */
    public const int MAX_DAYS = 92;

    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $from,
        public string $to,
    ) {
    }
}
