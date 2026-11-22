<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeleteCashMovement;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes a wrong movement, to enter it again (ADR 0033 §4). */
final readonly class DeleteCashMovementCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $movementId,
    ) {
    }
}
