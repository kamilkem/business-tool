<?php

declare(strict_types=1);

namespace App\Ksef\Application\Query\GetKsefConnection;

use App\Shared\Application\Query\QueryInterface;

/**
 * The current organization's KSeF connection for an owner or manager: status, whether a token is set
 * and when, the last check and the sync state; never the token. Null when the organization has none
 * or the actor is not an active member; throws when the actor is an employee (ADR 0021).
 */
final readonly class GetKsefConnectionQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
