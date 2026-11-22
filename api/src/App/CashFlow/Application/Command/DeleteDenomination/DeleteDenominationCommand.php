<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeleteDenomination;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes a denomination that no close or movement uses (ADR 0033 §2). */
final readonly class DeleteDenominationCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $denominationId,
    ) {
    }
}
