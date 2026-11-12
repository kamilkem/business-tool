<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueEntry;

use App\Shared\Application\Query\QueryInterface;

/**
 * One revenue entry of the current organization (TenantFilter), for an owner or manager. Returns null
 * when the entry does not exist there or the actor is not an active member; throws when the actor is
 * an employee (ADR 0022 §7).
 */
final readonly class GetRevenueEntryQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $entryId,
    ) {
    }
}
