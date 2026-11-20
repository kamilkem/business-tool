<?php

declare(strict_types=1);

namespace App\Secret\Application\Command\RemoveOrganizationSecret;

use App\Shared\Application\Command\CommandInterface;

/** An owner removes a secret of the organization; its value is gone for good (ADR 0026 §5). */
final readonly class RemoveOrganizationSecretCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $secretId,
    ) {
    }
}
