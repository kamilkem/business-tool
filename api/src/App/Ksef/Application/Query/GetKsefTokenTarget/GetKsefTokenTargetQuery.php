<?php

declare(strict_types=1);

namespace App\Ksef\Application\Query\GetKsefTokenTarget;

use App\Shared\Application\Query\QueryInterface;

/**
 * Before an owner saves or removes the KSeF token: checks that the actor may (an owner) and, when
 * saving, that KSeF is available to the organization (PL with a valid NIP), so that no secret is
 * stored for a request that would be refused. Answers the ids of the existing connection and secret,
 * which the caller reuses so that the secret is replaced in place (ADR 0026 §1). Null when the actor
 * is not an active member.
 */
final readonly class GetKsefTokenTargetQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        /** True to save a token (checks the country and NIP); false to remove it. */
        public bool $toSave,
    ) {
    }
}
