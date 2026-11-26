<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetMatchingStatus;

use App\Shared\Application\Query\QueryInterface;

/** What "Match again" would go through, whether the model is on, and the last run that used it (ADR 0046 §5). */
final readonly class GetMatchingStatusQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
