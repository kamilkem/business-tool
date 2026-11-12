<?php

declare(strict_types=1);

namespace App\Income\Application\Command\AddRegister;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager adds a register to the organization (ADR 0022 §2). */
final readonly class AddRegisterCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $registerId,
        public string $name,
    ) {
    }
}
