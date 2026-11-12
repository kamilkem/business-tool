<?php

declare(strict_types=1);

namespace App\Income\Application\Command\DeactivateRegister;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager deactivates a register: it takes no new entries, and its entries stay visible
 * and editable (ADR 0022 §2). Deactivating it again changes nothing.
 */
final readonly class DeactivateRegisterCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $registerId,
    ) {
    }
}
