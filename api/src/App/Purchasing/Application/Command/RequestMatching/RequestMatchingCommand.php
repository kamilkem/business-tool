<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\RequestMatching;

use App\Shared\Application\Command\CommandInterface;

/** "Match again" (ADR 0046 §4): an owner or manager has the organization's unassigned items put to the model again. */
final readonly class RequestMatchingCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
