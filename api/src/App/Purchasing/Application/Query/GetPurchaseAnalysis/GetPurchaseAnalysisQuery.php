<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetPurchaseAnalysis;

use App\Shared\Application\Query\QueryInterface;

/**
 * The overview of the purchase analysis of the current organization for the invoices issued from
 * `from` to `to` (`Y-m-d`, inclusive, at most MAX_DAYS days), for an owner or manager (ADR 0041 §6–8).
 * Returns null when the actor is not an active member; throws when the actor is an employee (ADR
 * 0021), the dates are not valid or the period is too long.
 */
final readonly class GetPurchaseAnalysisQuery implements QueryInterface
{
    public const int MAX_DAYS = 366;

    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $from,
        public string $to,
    ) {
    }
}
