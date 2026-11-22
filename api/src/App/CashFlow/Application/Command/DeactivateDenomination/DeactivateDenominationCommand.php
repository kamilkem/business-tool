<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeactivateDenomination;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deactivates a denomination: it is no longer offered (ADR 0033 §2). */
final readonly class DeactivateDenominationCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $denominationId,
    ) {
    }
}
