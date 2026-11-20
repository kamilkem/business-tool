<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\RemoveKsefToken;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner removed the organization's KSeF token (RemoveOrganizationSecretCommand, sent just before
 * by the same caller): the connection is disabled until a new token is saved.
 */
final readonly class RemoveKsefTokenCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
