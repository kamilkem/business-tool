<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListProductProposals;

use App\Shared\Application\Query\QueryInterface;

/**
 * The products the model proposes for the current organization's unmatched items, grouped by proposed
 * name, for an owner or manager (ADR 0044). Returns null when the actor is not an active member; throws
 * when the actor is an employee (ADR 0021).
 */
final readonly class ListProductProposalsQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
