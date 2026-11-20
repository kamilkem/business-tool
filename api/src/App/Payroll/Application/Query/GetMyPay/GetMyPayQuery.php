<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetMyPay;

use App\Shared\Application\Query\QueryInterface;

/**
 * The actor's own hours and gross pay in a calendar month (ADR 0027 §10, #66): their paycheck when an
 * approved run covers the month, otherwise an estimate from their ended shifts and rates. Always the
 * actor's own, whatever their role. Null when the actor is not an active member.
 */
final readonly class GetMyPayQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        /** YYYY-MM; null for the current month in the organization's time zone. */
        public ?string $month = null,
    ) {
    }
}
