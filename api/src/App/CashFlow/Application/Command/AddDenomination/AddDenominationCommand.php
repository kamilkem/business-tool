<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\AddDenomination;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager adds a denomination in the organization's currency (ADR 0033 §2). The value is
 * in minor units (ADR 0010); the kind is `coin` or `note`.
 */
final readonly class AddDenominationCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $denominationId,
        public int $value,
        public string $kind,
    ) {
    }
}
