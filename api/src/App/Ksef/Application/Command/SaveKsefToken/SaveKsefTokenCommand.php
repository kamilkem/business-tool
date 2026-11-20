<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\SaveKsefToken;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner saved the organization's KSeF token in the secret with this id (SetOrganizationSecretCommand,
 * sent just before by the same caller; ADR 0026 §5). Creates the connection with `connectionId` when
 * the organization has none, and makes it active with the token not verified yet. It never carries
 * the token.
 */
final readonly class SaveKsefTokenCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $connectionId,
        public string $secretId,
    ) {
    }
}
