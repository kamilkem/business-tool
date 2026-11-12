<?php

declare(strict_types=1);

namespace App\Income\Application\Command\RenameRegister;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager renames a register, active or deactivated (ADR 0022 §2). */
final readonly class RenameRegisterCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $registerId,
        public string $name,
    ) {
    }
}
