<?php

declare(strict_types=1);

namespace App\Secret\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/** An owner set or replaced a secret. Ids, kind and key id only, never the value (ADR 0026 §6). */
final readonly class OrganizationSecretSet implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $secretId,
        public string $kind,
        public string $keyId,
        public string $actorId,
    ) {
    }
}
