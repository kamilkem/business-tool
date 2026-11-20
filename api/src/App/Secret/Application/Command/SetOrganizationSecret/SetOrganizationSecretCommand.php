<?php

declare(strict_types=1);

namespace App\Secret\Application\Command\SetOrganizationSecret;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner stores a secret of the organization, or replaces the value of the one with this id
 * (ADR 0026 §5). The caller assigns the id of a new secret and keeps it. It carries a plaintext, so it
 * is never routed to a transport and has no __toString().
 */
final readonly class SetOrganizationSecretCommand implements CommandInterface
{
    public function __construct(
        public string $secretId,
        public string $organizationId,
        /** A SecretKind value. */
        public string $kind,
        #[\SensitiveParameter]
        public string $value,
        public string $actorId,
    ) {
    }
}
