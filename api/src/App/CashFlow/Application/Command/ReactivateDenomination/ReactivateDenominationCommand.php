<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\ReactivateDenomination;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager reactivates a denomination: it is offered again (ADR 0033 §2). */
final readonly class ReactivateDenominationCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $denominationId,
    ) {
    }
}
