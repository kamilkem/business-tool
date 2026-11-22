<?php

declare(strict_types=1);

namespace App\Income\Application\Command\DeleteRegister;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes a register no revenue entry refers to (ADR 0032). */
final readonly class DeleteRegisterCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $registerId,
    ) {
    }
}
