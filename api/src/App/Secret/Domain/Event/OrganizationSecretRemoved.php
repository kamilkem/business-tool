<?php

declare(strict_types=1);

namespace App\Secret\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/** An owner removed a secret. Ids and kind only (ADR 0026 §6). */
final readonly class OrganizationSecretRemoved implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $secretId,
        public string $kind,
        public string $actorId,
    ) {
    }
}
