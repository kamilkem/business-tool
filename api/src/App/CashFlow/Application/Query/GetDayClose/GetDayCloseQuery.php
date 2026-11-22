<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayClose;

use App\Shared\Application\Query\QueryInterface;

/**
 * One end-of-day close of the current organization, with its expected openings, for an owner or
 * manager (ADR 0033 §6). Returns null when the actor is not an active member or the close does not
 * exist; throws when the actor is an employee.
 */
final readonly class GetDayCloseQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $dayCloseId,
    ) {
    }
}
